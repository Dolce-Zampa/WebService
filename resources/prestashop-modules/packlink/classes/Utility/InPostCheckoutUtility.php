<?php
/**
 * 2026 DolceZampa
 *
 * The InPost checkout flow is fulfilled by Packlink. This helper intentionally
 * contains no InPost credentials or HTTP integration.
 */
namespace Packlink\PrestaShop\Classes\Utility;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Session and CSRF helpers for the Packlink InPost locations endpoint.
 */
class InPostCheckoutUtility
{
    const CSRF_COOKIE_KEY = 'packlink_inpost_locations_csrf';

    /**
     * Gets a per-browser token generated while rendering the checkout. It is
     * deliberately kept in the PrestaShop cookie, rather than in a request
     * parameter supplied by the browser.
     *
     * @param \Context $context
     *
     * @return string
     */
    public static function getCsrfToken(\Context $context)
    {
        $token = isset($context->cookie->{self::CSRF_COOKIE_KEY})
            ? (string)$context->cookie->{self::CSRF_COOKIE_KEY}
            : '';

        if ($token === '') {
            $token = \Tools::passwdGen(48, 'RANDOM');
            if ($token === false) {
                $token = sha1(uniqid((string)mt_rand(), true));
            }

            $context->cookie->{self::CSRF_COOKIE_KEY} = $token;
        }

        return $token;
    }

    /**
     * Checks that the request token belongs to the current browser session.
     *
     * @param \Context $context
     * @param string $token
     *
     * @return bool
     */
    public static function isValidCsrfToken(\Context $context, $token)
    {
        $expected = isset($context->cookie->{self::CSRF_COOKIE_KEY})
            ? (string)$context->cookie->{self::CSRF_COOKIE_KEY}
            : '';

        if ($expected === '' || !is_string($token) || strlen($expected) !== strlen($token)) {
            return false;
        }

        if (function_exists('hash_equals')) {
            return hash_equals($expected, $token);
        }

        $difference = 0;
        for ($index = 0; $index < strlen($expected); $index++) {
            $difference |= ord($expected[$index]) ^ ord($token[$index]);
        }

        return $difference === 0;
    }

    /**
     * Ensures that the cart originates from the current PrestaShop cookie.
     * This also supports guest checkout, whose cart is bound to id_guest.
     *
     * @param \Context $context
     *
     * @return bool
     */
    public static function isCurrentCartOwnedByContext(\Context $context)
    {
        if (empty($context->cart) || !\Validate::isLoadedObject($context->cart)) {
            return false;
        }

        if ((int)$context->cart->id !== (int)$context->cookie->id_cart) {
            return false;
        }

        if ((int)$context->cart->id_customer > 0
            && (int)$context->cart->id_customer !== (int)$context->cookie->id_customer
        ) {
            return false;
        }

        return (int)$context->cart->id_guest === (int)$context->cookie->id_guest;
    }
}
