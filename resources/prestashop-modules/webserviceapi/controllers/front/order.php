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
            $deliveryAddress = $isGuest
                ? $this->claimGuestCheckoutAddress($cart, 'id_address_delivery', $customer, $payload['delivery_address'])
                : MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['delivery_address']);
            $cart->id_address_delivery = (int) $deliveryAddress->id;
        }

        if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
            $invoiceAddress = $isGuest
                ? $this->claimGuestCheckoutAddress($cart, 'id_address_invoice', $customer, $payload['invoice_address'])
                : MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['invoice_address']);
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

        // The cart's secure_key must match the customer's, otherwise PaymentModule::validateOrder()
        // will reject the order with "Secure key does not match" (e.g. guest checkout, where the
        // cart was created before the customer existed and therefore has a different secure_key).
        $cart->secure_key = (string) $customer->secure_key;

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

        if (!empty($payload['coupon_code'])) {
            $result = $this->applyCouponToCart($cart, trim((string) $payload['coupon_code']));
            if (!$result['success']) {
                throw new MlabFactoryApiException($result['error'], 422);
            }
        }
        foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $existing) {
            $error = MlabFactoryCoupon::error(new CartRule((int) $existing['id_cart_rule']), $cart, $this->context);
            if ($error !== null) {
                throw new MlabFactoryApiException($error, 422);
            }
        }
        if (!array_key_exists('amount_paid', $payload)) {
            $amountPaid = (float) $cart->getOrderTotal(true, Cart::BOTH);
        }
        if (count($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false))) {
            if (!is_finite($amountPaid) || $amountPaid < 0 || abs($amountPaid - (float) $cart->getOrderTotal(true, Cart::BOTH)) > 0.01) {
                throw new MlabFactoryApiException('Paid amount does not match the discounted cart total.', 422);
            }
        } else {
            $this->addCustomCartRule($cart, $amountPaid);
        }

        $checkoutSnapshotId = (string) MlabFactoryApiHelper::getValue($payload, 'checkout_snapshot_id', '');
        if ($checkoutSnapshotId !== '') {
            $this->assertCheckoutSnapshotMatchesCart($cart, $checkoutSnapshotId, $carrierId, $amountPaid);
        }


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

    /**
     * Transfers a guest quote address to the customer created after payment.
     * The cart itself is the ownership boundary: only its currently attached
     * ownerless address can be claimed.
     */
    protected function claimGuestCheckoutAddress(Cart $cart, $property, Customer $customer, array $data)
    {
        $addressId = (int) $cart->$property;
        if ($addressId <= 0) {
            return MlabFactoryApiHelper::ensureAddressForCustomer($customer, $data);
        }

        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== 0) {
            return MlabFactoryApiHelper::ensureAddressForCustomer($customer, $data);
        }

        $address->id_customer = (int) $customer->id;
        if (!$address->update()) {
            throw new MlabFactoryApiException('Unable to claim guest checkout address.', 500);
        }

        $data['id_address'] = $addressId;
        return MlabFactoryApiHelper::ensureAddressForCustomer($customer, $data);
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
     * A buyer may have completed Stripe Checkout while the PrestaShop cart is
     * still editable. Confirm only when that cart still equals the immutable
     * server quote stored with the Checkout Session.
     */
    private function assertCheckoutSnapshotMatchesCart(Cart $cart, $snapshotId, $carrierId, $amountPaid)
    {
        if (!is_string($snapshotId) || !preg_match('/^[a-f0-9]{32}$/D', $snapshotId)) {
            throw new MlabFactoryApiException('Invalid checkout snapshot identifier.', 422);
        }

        $row = Db::getInstance()->getRow(
            'SELECT `verification_payload` FROM `' . _DB_PREFIX_ . 'stripe_checkout_snapshots`'
            . ' WHERE `id` = \'' . pSQL($snapshotId) . '\''
        );
        if (!$row || empty($row['verification_payload'])) {
            throw new MlabFactoryApiException('Checkout snapshot not found.', 409);
        }
        $snapshot = json_decode((string) $row['verification_payload'], true);
        if (!is_array($snapshot) || !isset($snapshot['totals']) || !is_array($snapshot['totals']) || !isset($snapshot['lines']) || !is_array($snapshot['lines'])) {
            throw new MlabFactoryApiException('Checkout snapshot is invalid.', 409);
        }

        $currency = new Currency((int) $cart->id_currency);
        if ((int) ($snapshot['cart_id'] ?? 0) !== (int) $cart->id
            || (int) ($snapshot['carrier_id'] ?? 0) !== (int) $carrierId
            || strtoupper((string) ($snapshot['currency'] ?? '')) !== strtoupper((string) $currency->iso_code)
            || (int) ($snapshot['totals']['grand_total_cents'] ?? -1) !== $this->minorAmount($amountPaid)) {
            throw new MlabFactoryApiException('Checkout snapshot does not match the order confirmation.', 409);
        }

        if ($this->minorAmount($cart->getOrderTotal(true, Cart::BOTH)) !== (int) ($snapshot['totals']['grand_total_cents'] ?? -1)) {
            throw new MlabFactoryApiException('Cart total changed after Checkout was created.', 409);
        }

        $expectedLines = array();
        foreach ($snapshot['lines'] as $line) {
            if (!is_array($line)) {
                throw new MlabFactoryApiException('Checkout snapshot is invalid.', 409);
            }
            $expectedLines[] = array(
                'product_id' => (int) ($line['product_id'] ?? 0),
                'product_attribute_id' => (int) ($line['product_attribute_id'] ?? 0),
                'customization_id' => (int) ($line['customization_id'] ?? 0),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_amount_tax_incl_cents' => (int) ($line['unit_amount_tax_incl_cents'] ?? -1),
                'total_amount_tax_incl_cents' => (int) ($line['total_amount_tax_incl_cents'] ?? -1),
            );
        }
        $actualLines = array();
        foreach ($cart->getProducts() as $line) {
            $actualLines[] = array(
                'product_id' => (int) ($line['id_product'] ?? 0),
                'product_attribute_id' => (int) ($line['id_product_attribute'] ?? 0),
                'customization_id' => (int) ($line['id_customization'] ?? 0),
                'quantity' => (int) ($line['cart_quantity'] ?? 0),
                'unit_amount_tax_incl_cents' => $this->minorAmount($line['price_wt'] ?? null),
                'total_amount_tax_incl_cents' => $this->minorAmount($line['total_wt'] ?? null),
            );
        }
        usort($expectedLines, array($this, 'sortSnapshotLines'));
        usort($actualLines, array($this, 'sortSnapshotLines'));
        if ($expectedLines !== $actualLines) {
            throw new MlabFactoryApiException('Cart products changed after Checkout was created.', 409);
        }
    }

    private function sortSnapshotLines($left, $right)
    {
        return strcmp(json_encode($left), json_encode($right));
    }

    private function minorAmount($amount)
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw new MlabFactoryApiException('Invalid checkout amount.', 409);
        }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
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

        $error = MlabFactoryCoupon::error($cartRule, $cart, $context);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        if (!MlabFactoryCoupon::contains($cart, $cartRule->id) && !$cart->addCartRule($cartRule->id)) {
            return ['success' => false, 'error' => 'Unable to apply coupon.'];
        }
        if (!$cart->update()) {
            return ['success' => false, 'error' => 'Unable to persist coupon.'];
        }

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
