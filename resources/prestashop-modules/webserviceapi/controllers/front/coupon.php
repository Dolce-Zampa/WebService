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

        if ($couponCode === '') {
            throw new MlabFactoryApiException('Coupon code is required.', 422);
        }

        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer', 0);
        $idGuest = (int) MlabFactoryApiHelper::getValue($payload, 'id_guest', 0);
        $cart = $this->getOwnedCart($idCart, $idCustomer, $idGuest);
        $validation = $this->validateCouponForCart($cart, $couponCode);

        if (!$validation['valid']) {
            throw new MlabFactoryApiException((string) $validation['message'], 422);
        }

        $coupon = isset($validation['coupon']) && is_array($validation['coupon']) ? $validation['coupon'] : array();
        if (empty($coupon['id'])) {
            throw new MlabFactoryApiException('Coupon not found.', 404, array('code' => $couponCode));
        }

        if (!$cart->addCartRule((int) $coupon['id'])) {
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

        $where = 'cr.`deleted` = 0';
        if ($couponCode !== '') {
            $where .= ' AND cr.`code` = \'' . pSQL($couponCode) . '\'';
        }
        if ($shopId > 0) {
            $where .= ' AND (crs.`id_shop` = ' . $shopId . ' OR crs.`id_shop` IS NULL)';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT cr.`id_cart_rule`, cr.`code`, cr.`date_to`, cr.`quantity`, cr.`active`,
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

        $this->applyCartContext($cart);
        $check = $cartRule->checkValidity($this->context, false, true);

        return array(
            'valid' => $check === true,
            'message' => $check === true ? 'Coupon valido.' : (string) $check,
            'coupon' => array(
                'id' => (int) $cartRule->id,
                'code' => (string) $cartRule->code,
                'name' => is_array($cartRule->name) && isset($cartRule->name[(int) $this->context->language->id])
                    ? (string) $cartRule->name[(int) $this->context->language->id]
                    : '',
                'date_to' => (string) $cartRule->date_to,
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

    protected function applyCartContext(Cart $cart)
    {
        $this->context->cart = $cart;

        if ((int) $cart->id_customer > 0) {
            $customer = new Customer((int) $cart->id_customer);
            if (Validate::isLoadedObject($customer)) {
                $this->getCustomerContext($customer);
            }
        }

        if ($this->context->cookie) {
            $this->context->cookie->id_cart = (int) $cart->id;
            $this->context->cookie->write();
        }
    }

    protected function serializeCartRuleRow(array $row)
    {
        return array(
            'id' => isset($row['id_cart_rule']) ? (int) $row['id_cart_rule'] : 0,
            'code' => isset($row['code']) ? (string) $row['code'] : '',
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'date_to' => isset($row['date_to']) ? (string) $row['date_to'] : '',
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
            'active' => isset($row['active']) ? (bool) $row['active'] : false,
            'reduction_percent' => isset($row['reduction_percent']) ? (float) $row['reduction_percent'] : 0.0,
            'reduction_amount' => isset($row['reduction_amount']) ? (float) $row['reduction_amount'] : 0.0,
        );
    }
}
