<?php
require_once __DIR__ . '/coupon.php';
require_once __DIR__ . '/../../classes/MlabMarketplaceShipping.php';

/** Calculates a quote in a transaction which is always rolled back. */
class webserviceapishippingquoteModuleFrontController extends webserviceapicouponModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));
        MlabMarketplaceShipping::ensureReady($this->module, $this->context);
        $payload = $this->getJsonPayload();
        $idCart = (int) ($payload['id_cart'] ?? 0);
        $idCustomer = (int) ($payload['id_customer'] ?? 0);
        $idGuest = (int) ($payload['id_guest'] ?? 0);
        $carrierId = (int) ($payload['id_carrier'] ?? 0);
        $addressData = $payload['delivery_address'] ?? null;
        $invoiceData = $payload['invoice_address'] ?? null;
        if ($idCart <= 0 || $carrierId <= 0 || !is_array($addressData) || !is_array($invoiceData)) {
            throw new MlabFactoryApiException('Invalid shipping quote.', 422);
        }
        $cart = $this->getOwnedCart($idCart, $idCustomer, $idGuest);
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new MlabFactoryApiException('Unable to start shipping quote.', 500);
        }
        try {
            $customer = $idCustomer > 0 ? MlabFactoryApiHelper::ensureCustomerExists($idCustomer) : new Customer();
            if ($idCustomer <= 0) {
                $customer->firstname = (string) ($payload['firstname'] ?? 'Guest');
                $customer->lastname = (string) ($payload['lastname'] ?? 'Customer');
            }
            unset($addressData['id_address'], $invoiceData['id_address']);
            $delivery = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $addressData);
            $invoice = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $invoiceData);
            $cart->id_address_delivery = (int) $delivery->id;
            $cart->id_address_invoice = (int) $invoice->id;
            $cart->id_carrier = $carrierId;
            MlabFactoryCoupon::setContext($cart, $this->context);
            $cart->setDeliveryOption(array((int) $delivery->id => $carrierId . ','));
            $options = $cart->getDeliveryOptionList();
            if (!isset($options[(int) $delivery->id][$carrierId . ','])) {
                throw new MlabFactoryApiException('Carrier unavailable for this cart.', 422);
            }
            $shipping = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            if (!is_numeric($shipping) || !is_finite((float) $shipping) || (float) $shipping < 0) {
                throw new MlabFactoryApiException('Shipping quote unavailable.', 422);
            }
            return array('shipping_cents' => (int) round((float) $shipping * 100));
        } finally {
            $db->execute('ROLLBACK');
        }
    }
}
