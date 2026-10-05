<?php
/** Shared coupon validation for cart and order endpoints. */
class MlabFactoryCoupon
{
    public static function setContext(Cart $cart, $context)
    {
        $context->cart = $cart;
        $context->customer = new Customer((int) $cart->id_customer);
        $context->currency = new Currency((int) $cart->id_currency);
        $context->language = new Language((int) $cart->id_lang);
        $country = Address::getCountryAndState((int) $cart->id_address_delivery);
        $context->country = new Country((int) ($country['id_country'] ?? Configuration::get('PS_COUNTRY_DEFAULT')));
    }

    public static function contains(Cart $cart, $id)
    {
        foreach ($cart->getCartRules(CartRule::FILTER_ACTION_ALL, false) as $rule) {
            if ((int) $rule['id_cart_rule'] === (int) $id) {
                return true;
            }
        }
        return false;
    }

    public static function error(CartRule $rule, Cart $cart, $context)
    {
        self::setContext($cart, $context);
        // PrestaShop skips these checks for rules already attached to the cart.
        if (!$rule->active || (int) $rule->quantity <= 0 ||
            strtotime($rule->date_from) > time() || strtotime($rule->date_to) < time()) {
            return 'Coupon is inactive, expired or exhausted.';
        }
        $result = $rule->checkValidity($context, self::contains($cart, $rule->id), true);
        // With display_error=true, successful validation returns null.
        return $result === null || $result === true ? null : (is_string($result) ? $result : 'Coupon is not valid.');
    }
}
