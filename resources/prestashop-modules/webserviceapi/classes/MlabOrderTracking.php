<?php

/** Tracking information for one PrestaShop order. */
class MlabOrderTracking
{
    public static function forOrder($order)
    {
        $number = '';
        try {
            $number = trim((string) $order->getShippingNumber());
        } catch (\Throwable $e) {
            // Tracking is optional; the order can still be displayed.
        }
        $url = null;

        // Packlink stores the carrier's per-shipment URL separately from the
        // tracking number copied to PrestaShop's order_carrier table.
        try {
            if (class_exists('Module') && Module::isEnabled('packlink') && Module::getInstanceByName('packlink')) {
                \Packlink\PrestaShop\Classes\Bootstrap::init();
                $service = \Logeecom\Infrastructure\ServiceRegister::getService(
                    \Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService::CLASS_NAME
                );
                $details = $service->getDetailsByOrderId((string) $order->id);
                if ($details && !$details->isDeleted()) {
                    foreach ((array) $details->getCarrierTrackingNumbers() as $candidate) {
                        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                            $number = trim((string) $candidate);
                            break;
                        }
                    }
                    $url = self::safeUrl($details->getCarrierTrackingUrl());
                }
            }
        } catch (\Throwable $e) {
            // A missing or unavailable optional integration must not prevent
            // order details or transactional email from being delivered.
        }

        try {
            if ($url === null && (int) $order->id_carrier > 0) {
                $carrier = new Carrier((int) $order->id_carrier);
                if (Validate::isLoadedObject($carrier)) {
                    $template = trim((string) $carrier->url);
                    if (strpos($template, '@') === false || $number !== '') {
                        $url = self::safeUrl(str_replace('@', rawurlencode($number), $template));
                    }
                }
            }
        } catch (\Throwable $e) {
            // No carrier link is better than blocking the order or email.
        }

        return array('number' => $number !== '' ? $number : null, 'url' => $url);
    }

    private static function safeUrl($value)
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        return $url;
    }
}
