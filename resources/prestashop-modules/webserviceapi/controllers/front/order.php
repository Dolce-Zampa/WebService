<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapiorderModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'POST'));

        if ($method === 'GET') {
            return $this->handleGetRequest();
        }

        $payload = $this->getJsonPayload();
        MlabFactoryApiHelper::requireFields($payload, array('id_cart'));

        $cart = new Cart((int) $payload['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            throw new MlabFactoryApiException('Cart not found.', 404, array('id_cart' => (int) $payload['id_cart']));
        }

        if (!count($cart->getProducts())) {
            throw new MlabFactoryApiException('Cart is empty.', 422, array('id_cart' => (int) $cart->id));
        }

        // ========== APPLICA IL COUPON SE PRESENTE ==========
        if (!empty($payload['coupon_code'])) {
            $result = $this->applyCouponToCart($cart, $payload['coupon_code']);

            if (!$result['success']) {
                throw new MlabFactoryApiException($result['error'], 422, ['coupon_code' => $payload['coupon_code']]);
            }

            // Ricarica il carrello con lo sconto applicato
            $cart = new Cart((int) $cart->id);
        }
        // ===================================================

        // Determine if this is a guest or registered customer
        $isGuest = (int) $cart->id_customer === 0 && (int) $cart->id_guest > 0;

        if ($isGuest) {
            // Guest checkout - create temporary customer from guest and cart data
            $customer = MlabFactoryApiHelper::createCustomerFromGuest($cart, $payload);
        } else {
            // Registered customer
            $customer = MlabFactoryApiHelper::ensureCustomerExists((int) $cart->id_customer);
        }

        if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
            $deliveryAddress = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['delivery_address']);
            $cart->id_address_delivery = (int) $deliveryAddress->id;
        }

        if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
            $invoiceAddress = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['invoice_address']);
            $cart->id_address_invoice = (int) $invoiceAddress->id;
        }

        if (!(int) $cart->id_address_delivery || !(int) $cart->id_address_invoice) {
            throw new MlabFactoryApiException('Delivery and invoice addresses are required before finalizing the order.', 422);
        }

        $carrierId = MlabFactoryApiHelper::resolveCarrierId($cart, $payload);
        if ($carrierId > 0) {
            $cart->id_carrier = $carrierId;
            $cart->setDeliveryOption(array((int) $cart->id_address_delivery => $carrierId . ','));
        }

        // Update cart with customer if it was a guest
        if ($isGuest) {
            $cart->id_customer = (int) $customer->id;
        }

        if (!$cart->update()) {
            throw new MlabFactoryApiException('Unable to update cart before order creation.', 500);
        }

        $paymentModuleName = (string) MlabFactoryApiHelper::getValue($payload, 'payment_module', Configuration::get(webserviceapi::CONFIG_PAYMENT_MODULE));
        $paymentModule = MlabFactoryApiHelper::resolvePaymentModule($paymentModuleName);
        $orderStateId = (int) MlabFactoryApiHelper::getValue($payload, 'id_order_state');
        $paymentLabel = (string) MlabFactoryApiHelper::getValue($payload, 'payment_label', $paymentModule->displayName);
        $amountPaid = (float) MlabFactoryApiHelper::getValue($payload, 'amount_paid', $cart->getOrderTotal(true, Cart::BOTH));

        $this->context->cart = $cart;
        $this->context->customer = $customer;
        $this->context->currency = new Currency((int) $cart->id_currency);
        $this->context->language = new Language((int) $cart->id_lang);
        $countryState = Address::getCountryAndState((int) $cart->id_address_delivery);
        $countryId = is_array($countryState) && !empty($countryState['id_country'])
            ? (int) $countryState['id_country']
            : (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $this->context->country = new Country($countryId);

        // Check if order already exists for this cart
        $existingOrderId = (int) Db::getInstance()->getValue(
            'SELECT `id_order`
            FROM `' . _DB_PREFIX_ . 'orders`
            WHERE `id_cart` = ' . (int) $cart->id
        );

        if ($existingOrderId > 0) {
            $order = new Order($existingOrderId);

            return array(
                'message' => 'Order already exists for this cart.',
                'order' => MlabFactoryApiHelper::serializeOrder($order),
            );
        }

        $this->addCustomCartRule($cart, $amountPaid);

        $paymentModule->validateOrder(
            (int) $cart->id,
            $orderStateId,
            $amountPaid,
            $paymentLabel,
            null,
            array(),
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $orderId = (int) $paymentModule->currentOrder;
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new MlabFactoryApiException('Order was not created.', 500, array('id_cart' => (int) $cart->id));
        }

        return array(
            'message' => 'Order finalized successfully.',
            'order' => MlabFactoryApiHelper::serializeOrder($order),
            'cart' => MlabFactoryApiHelper::serializeCart($cart),
            'guest_registered' => $isGuest,
        );
    }

    private function addCustomCartRule(Cart $cart, float $amountPaid)
    {
        $cart = new Cart((int) $cart->id);

        // Totale reale calcolato da PrestaShop
        $psTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Totale deciso dalla tua API
        $customTotal = (float) $amountPaid;

        // Differenza
        $difference = round($psTotal - $customTotal, 2);

        // Applichiamo regola solo se necessario
        if (abs($difference) > 0.01) {

            // Se differenza positiva → Sconto (OK, teniamo la tua logica originale)
            if ($difference > 0) {
                $cartRule = new CartRule();
                $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
                $cartRule->name = [$defaultLang => 'Custom API price adjustment'];
                $cartRule->id_customer = (int) $cart->id_customer;
                $cartRule->quantity = 1;
                $cartRule->quantity_per_user = 1;
                $cartRule->reduction_amount = $difference;
                $cartRule->reduction_tax = true;
                $cartRule->date_from = date('Y-m-d H:i:s');
                $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $cartRule->active = 1;
                $cartRule->add();
                $cart->addCartRule($cartRule->id);
            }
            // Se differenza negativa → Sovrapprezzo (usiamo un PRODOTTO)
            else {
                $fee_amount = abs($difference); // Importo del sovrapprezzo (positivo)

                // 1. Definisci l'ID di un prodotto "fantasma" che userai come fee
                //    (es. ID 9999 - crealo manualmente dal backoffice, disabilitalo dalla vetrina)
                $id_product_fee = 151;

                // 2. Imposta un prezzo specifico temporaneo per questo prodotto/carrello
                $specific_price = new SpecificPrice();
                $specific_price->id_product = $id_product_fee;
                $specific_price->id_cart = (int) $cart->id; // Lega il prezzo a QUESTO carrello
                $specific_price->id_shop = (int) $cart->id_shop;
                $specific_price->id_currency = (int) $cart->id_currency;
                $specific_price->id_country = (int) Context::getContext()->country->id;
                $specific_price->id_customer = (int) $cart->id_customer;
                $specific_price->price = $fee_amount; // Qui imposti l'importo della fee
                $specific_price->from_quantity = 1;
                $specific_price->reduction_type = 'amount';
                $specific_price->reduction_tax = 1;
                $specific_price->reduction = 0;
                $specific_price->from = date('Y-m-d H:i:s');
                $specific_price->to = date('Y-m-d H:i:s', strtotime('+1 hour')); // Valido 1 ora

                try {
                    $specific_price->add();
                    // Aggiungi 1 pezzo del prodotto fee al carrello
                    $cart->updateQty(1, $id_product_fee);
                } catch (Exception $e) {
                    // Gestisci l'errore (es. logga)
                    PrestaShopLogger::addLog("Errore nell'aggiunta della fee: " . $e->getMessage());
                }
            }
        }
    }

    protected function handleGetRequest()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $reference = trim((string) Tools::getValue('reference'));
        $idCustomer = (int) Tools::getValue('id_customer');
        $idGuest = (int) Tools::getValue('id_guest');

        if ($idOrder <= 0 && $reference === '') {
            if ($idCustomer > 0 || $idGuest > 0) {
                return $this->getOrderHistory($idCustomer, $idGuest);
            }

            throw new MlabFactoryApiException('You must provide id_order or reference.', 422);
        }

        if ($idCustomer > 0 && $idGuest > 0) {
            throw new MlabFactoryApiException('Provide only one owner identifier: id_customer or id_guest.', 422);
        }

        $order = $idOrder > 0 ? new Order($idOrder) : $this->getOrderByReference($reference);
        if (!Validate::isLoadedObject($order)) {
            throw new MlabFactoryApiException('Order not found.', 404, array(
                'id_order' => $idOrder,
                'reference' => $reference,
            ));
        }

        if ($idCustomer > 0 && (int) $order->id_customer !== $idCustomer) {
            throw new MlabFactoryApiException('Order does not belong to the customer.', 422, array('id_order' => (int) $order->id));
        }

        if ($idGuest > 0) {
            $cart = new Cart((int) $order->id_cart);
            if (!Validate::isLoadedObject($cart) || (int) $cart->id_guest !== $idGuest) {
                throw new MlabFactoryApiException('Order does not belong to the guest.', 422, array('id_order' => (int) $order->id));
            }
        }

        return array(
            'message' => 'Order retrieved successfully.',
            'order' => MlabFactoryApiHelper::serializeOrder($order),
        );
    }

    protected function getOrderHistory(int $idCustomer, int $idGuest)
    {
        $query = new DbQuery();
        $query->select('o.`id_order`');
        $query->from('orders', 'o');
        $query->leftJoin('cart', 'c', 'c.`id_cart` = o.`id_cart`');

        if ($idCustomer > 0) {
            $query->where('o.`id_customer` = ' . (int) $idCustomer);
        } elseif ($idGuest > 0) {
            $query->where('c.`id_guest` = ' . (int) $idGuest);
        } else {
            throw new MlabFactoryApiException('You must provide id_customer or id_guest.', 422);
        }
        $query->orderBy('o.`date_add` DESC');

        $rows = Db::getInstance()->executeS($query);

        $orders = [];
        foreach ($rows as $row) {
            $order = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($order)) {
                $orders[] = MlabFactoryApiHelper::serializeOrder($order);
            }
        }

        return array(
            'message' => 'Order history retrieved successfully.',
            'orders' => $orders,
        );
    }

    protected function getOrderByReference($reference)
    {
        $orderId = (int) Db::getInstance()->getValue(
            'SELECT `id_order`
            FROM `' . _DB_PREFIX_ . 'orders`
            WHERE `reference` = \'' . pSQL($reference) . '\'
            ORDER BY `id_order` DESC'
        );

        if ($orderId <= 0) {
            return null;
        }

        return new Order($orderId);
    }
    /**
     * Applica una cart rule (coupon) al carrello
     * 
     * @param Cart $cart
     * @param string $couponCode Codice sconto da applicare
     * @return array Risultato dell'operazione
     */
    private function applyCouponToCart(Cart $cart, string $couponCode): array
    {
        // Inizializza il contesto
        $context = Context::getContext();
        $context->cart = $cart;
        $context->customer = new Customer($cart->id_customer);

        // Cerca la cart rule per codice
        $cartRule = new CartRule(CartRule::getIdByCode($couponCode));

        if (!Validate::isLoadedObject($cartRule)) {
            return [
                'success' => false,
                'error' => 'Coupon code not found.'
            ];
        }

        // Verifica se la cart rule è valida per questo carrello/customer
        if (!$cartRule->checkValidity($context, $cart->id, false)) {
            $errors = $cartRule->getValidityErrors();
            return [
                'success' => false,
                'error' => 'Coupon is not valid: ' . implode(', ', $errors)
            ];
        }

        // Rimuovi eventuali cart rule esistenti (opzionale)
        $cart->removeCartRules();

        // Applica la cart rule al carrello
        $cart->addCartRule($cartRule->id);

        // Aggiorna il carrello per ricalcolare i totali
        $cart->update();

        return [
            'success' => true,
            'message' => 'Coupon applied successfully.',
            'cart_rule' => [
                'id' => $cartRule->id,
                'name' => $cartRule->name[(int) $cart->id_lang],
                'code' => $cartRule->code,
                'reduction_percent' => $cartRule->reduction_percent,
                'reduction_amount' => $cartRule->reduction_amount
            ]
        ];
    }
}
