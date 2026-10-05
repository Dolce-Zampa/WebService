<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapicouponModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'POST'));

        if ($method === 'GET') {
            return $this->handleGetRequest();
        }

        return $this->handlePostRequest();
    }

    protected function handleGetRequest()
    {
        $idCart = (int) Tools::getValue('id_cart');
        $couponCode = trim((string) Tools::getValue('code', ''));

        if ($couponCode !== '' && $idCart > 0) {
            $idCustomer = (int) Tools::getValue('id_customer');
            $idGuest = (int) Tools::getValue('id_guest');

            $cart = $this->getOwnedCart($idCart, $idCustomer, $idGuest);
            $validation = $this->validateCouponForCart($cart, $couponCode);

            return array(
                'valid' => $validation['valid'],
                'message' => $validation['message'],
                'coupon' => $validation['coupon'],
            );
        }

        return array(
            'cart_rules' => $this->listCartRules($couponCode),
        );
    }

    protected function handlePostRequest()
    {
        $payload = $this->getJsonPayload();
        $idCart = (int) MlabFactoryApiHelper::getValue($payload, 'id_cart', 0);
        $couponCode = trim((string) MlabFactoryApiHelper::getValue(
            $payload,
            'code',
            MlabFactoryApiHelper::getValue($payload, 'discount_name', '')
        ));

        if ($idCart <= 0) {
            throw new MlabFactoryApiException('id_cart is required.', 422);
        }

        if ($couponCode === '' && empty($payload['checkout'])) {
            throw new MlabFactoryApiException('Coupon code is required.', 422);
        }

        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer', 0);
        $idGuest = (int) MlabFactoryApiHelper::getValue($payload, 'id_guest', 0);
        $cart = $this->getOwnedCart($idCart, $idCustomer, $idGuest);
        if (!empty($payload['checkout'])) {
            if (!empty($payload['delivery_address']) || !empty($payload['invoice_address'])) {
                $customer = (int) $cart->id_customer > 0
                    ? MlabFactoryApiHelper::ensureCustomerExists((int) $cart->id_customer)
                    : MlabFactoryApiHelper::createCustomerFromGuest($cart, $payload);
                $cart->id_customer = (int) $customer->id;
                $cart->secure_key = (string) $customer->secure_key;
                foreach (array('delivery', 'invoice') as $type) {
                    $field = $type . '_address';
                    if (!empty($payload[$field]) && is_array($payload[$field])) {
                        $address = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload[$field]);
                        $property = 'id_address_' . $type;
                        $cart->$property = (int) $address->id;
                    }
                }
            }
            $carrierId = MlabFactoryApiHelper::resolveCarrierId($cart, $payload);
            if ($carrierId <= 0 && !$cart->isVirtualCart()) {
                throw new MlabFactoryApiException('Carrier is required for checkout.', 422);
            }
            $cart->id_carrier = $carrierId;
            $cart->setDeliveryOption(array((int) $cart->id_address_delivery => $carrierId . ','));
            if (!$cart->update()) {
                throw new MlabFactoryApiException('Unable to update checkout cart.', 500);
            }
            foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $existing) {
                $error = MlabFactoryCoupon::error(new CartRule((int) $existing['id_cart_rule']), $cart, $this->context);
                if ($error !== null) {
                    throw new MlabFactoryApiException($error, 422);
                }
            }
            if ($couponCode === '') {
                MlabFactoryCoupon::setContext($cart, $this->context);
                return array('cart' => MlabFactoryApiHelper::serializeCart($cart));
            }
        }
        $validation = $this->validateCouponForCart($cart, $couponCode);

        if (!$validation['valid']) {
            throw new MlabFactoryApiException((string) $validation['message'], 422);
        }

        $coupon = isset($validation['coupon']) && is_array($validation['coupon']) ? $validation['coupon'] : array();
        if (empty($coupon['id'])) {
            throw new MlabFactoryApiException('Coupon not found.', 404, array('code' => $couponCode));
        }

        if (!MlabFactoryCoupon::contains($cart, $coupon['id']) && !$cart->addCartRule((int) $coupon['id'])) {
            throw new MlabFactoryApiException('Unable to apply coupon to cart.', 422, array('code' => $couponCode));
        }

        if (!$cart->update()) {
            throw new MlabFactoryApiException('Unable to persist cart coupon.', 500, array('id_cart' => (int) $cart->id));
        }

        return array(
            'message' => 'Coupon applied successfully.',
            'coupon' => $coupon,
            'cart' => MlabFactoryApiHelper::serializeCart($cart),
        );
    }

    protected function listCartRules($couponCode)
    {
        $languageId = isset($this->context->language) && Validate::isLoadedObject($this->context->language)
            ? (int) $this->context->language->id
            : (int) Configuration::get('PS_LANG_DEFAULT');
        $shopId = isset($this->context->shop) && Validate::isLoadedObject($this->context->shop)
            ? (int) $this->context->shop->id
            : 0;

        $where = 'cr.`active` = 1';
        if ($couponCode !== '') {
            $where .= ' AND cr.`code` = \'' . pSQL($couponCode) . '\'';
        }
        if ($shopId > 0) {
            $where .= ' AND (crs.`id_shop` = ' . $shopId . ' OR crs.`id_shop` IS NULL)';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT cr.`id_cart_rule`, cr.`code`, cr.`date_from`, cr.`date_to`, cr.`quantity`, cr.`active`,
                    cr.`reduction_percent`, cr.`reduction_amount`, crl.`name`
             FROM `' . _DB_PREFIX_ . 'cart_rule` cr
             LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl ON (
                crl.`id_cart_rule` = cr.`id_cart_rule`
                AND crl.`id_lang` = ' . $languageId . '
             )
             LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_shop` crs ON (crs.`id_cart_rule` = cr.`id_cart_rule`)
             WHERE ' . $where . '
             ORDER BY cr.`date_to` DESC, cr.`id_cart_rule` DESC'
        );

        $rules = array();
        foreach ((array) $rows as $row) {
            $rules[] = $this->serializeCartRuleRow($row);
        }

        return $rules;
    }

    protected function validateCouponForCart(Cart $cart, $couponCode)
    {
        $couponCode = trim((string) $couponCode);
        if ($couponCode === '') {
            return array(
                'valid' => false,
                'message' => 'Coupon code is required.',
                'coupon' => null,
            );
        }

        $idCartRule = (int) CartRule::getIdByCode($couponCode);
        if ($idCartRule <= 0) {
            return array(
                'valid' => false,
                'message' => 'Coupon not found.',
                'coupon' => array('code' => $couponCode),
            );
        }

        $cartRule = new CartRule($idCartRule);
        if (!Validate::isLoadedObject($cartRule)) {
            return array(
                'valid' => false,
                'message' => 'Coupon not found.',
                'coupon' => array('id' => $idCartRule, 'code' => $couponCode),
            );
        }

        $check = MlabFactoryCoupon::error($cartRule, $cart, $this->context);

        return array(
            'valid' => $check === null,
            'message' => $check === null ? 'Coupon is valid.' : $check,
            'coupon' => array(
                'id' => (int) $cartRule->id,
                'code' => (string) $cartRule->code,
                'name' => is_array($cartRule->name) && isset($cartRule->name[(int) $this->context->language->id])
                    ? (string) $cartRule->name[(int) $this->context->language->id]
                    : '',
                'date_from' => (string) $cartRule->date_from,
                'date_to' => (string) $cartRule->date_to,
                'valid_from' => (string) $cartRule->date_from,
                'valid_to' => (string) $cartRule->date_to,
                'quantity' => (int) $cartRule->quantity,
                'reduction_percent' => (float) $cartRule->reduction_percent,
                'reduction_amount' => (float) $cartRule->reduction_amount,
            ),
        );
    }

    protected function getOwnedCart($idCart, $idCustomer, $idGuest)
    {
        if ($idCustomer <= 0 && $idGuest <= 0) {
            throw new MlabFactoryApiException('You must provide id_customer or id_guest.', 422);
        }

        if ($idCustomer > 0 && $idGuest > 0) {
            throw new MlabFactoryApiException('Provide only one owner identifier: id_customer or id_guest.', 422);
        }

        $cart = new Cart((int) $idCart);
        if (!Validate::isLoadedObject($cart)) {
            throw new MlabFactoryApiException('Cart not found.', 404, array('id_cart' => (int) $idCart));
        }

        if ($idCustomer > 0 && (int) $cart->id_customer !== (int) $idCustomer) {
            throw new MlabFactoryApiException('Cart does not belong to the customer.', 422, array('id_cart' => (int) $idCart));
        }

        if ($idGuest > 0 && (int) $cart->id_guest !== (int) $idGuest) {
            throw new MlabFactoryApiException('Cart does not belong to the guest.', 422, array('id_cart' => (int) $idCart));
        }

        return $cart;
    }

    protected function serializeCartRuleRow(array $row)
    {
        return array(
            'id' => isset($row['id_cart_rule']) ? (int) $row['id_cart_rule'] : 0,
            'code' => isset($row['code']) ? (string) $row['code'] : '',
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'date_from' => isset($row['date_from']) ? (string) $row['date_from'] : '',
            'date_to' => isset($row['date_to']) ? (string) $row['date_to'] : '',
            'valid_from' => isset($row['date_from']) ? (string) $row['date_from'] : '',
            'valid_to' => isset($row['date_to']) ? (string) $row['date_to'] : '',
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
            'active' => isset($row['active']) ? (bool) $row['active'] : false,
            'reduction_percent' => isset($row['reduction_percent']) ? (float) $row['reduction_percent'] : 0.0,
            'reduction_amount' => isset($row['reduction_amount']) ? (float) $row['reduction_amount'] : 0.0,
        );
    }
}
