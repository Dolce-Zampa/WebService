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

    protected function handleGetRequest()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $reference = trim((string) Tools::getValue('reference'));
        $idCustomer = (int) Tools::getValue('id_customer');
        $idGuest = (int) Tools::getValue('id_guest');

        if ($idOrder <= 0 && $reference === '') {
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
}
