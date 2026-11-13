<?php

/** Read the Italian carrier rates and preserve their configured ranges. */
final class MlabFixedCarrierRates
{
    public static function prices($carrier, $zoneId, $countryId)
    {
        if ($carrier->is_free) return array('price_with_tax' => 0.0, 'price_ranges' => array(), 'price_varies_by_range' => false);
        if ($carrier->shipping_external) return self::unavailable();
        $method = $carrier->getShippingMethod();
        if ($method === Carrier::SHIPPING_METHOD_WEIGHT) $range = 'range_weight';
        elseif ($method === Carrier::SHIPPING_METHOD_PRICE) $range = 'range_price';
        else return self::unavailable();

        $sql = 'SELECT d.`price`, r.`delimiter1`, r.`delimiter2` FROM `' . _DB_PREFIX_ . 'delivery` d'
            . ' INNER JOIN `' . _DB_PREFIX_ . $range . '` r ON d.`id_' . $range . '` = r.`id_' . $range . '`'
            . ' WHERE d.`id_carrier` = ' . (int) $carrier->id . ' AND d.`id_zone` = ' . (int) $zoneId
            . ' ' . Carrier::sqlDeliveryRangeShop($range) . ' ORDER BY r.`delimiter1` ASC';
        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows) || $rows === array()) return self::unavailable();
        $tax = 0.0;
        if (Configuration::get('PS_TAX') && !Configuration::get('PS_ATCP_SHIPWRAP')) {
            $address = new Address();
            $address->id_country = (int) $countryId;
            $tax = (float) $carrier->getTaxesRate($address);
        }
        $ranges = array();
        foreach ($rows as $row) {
            if (!is_numeric($row['price'] ?? null) || !is_numeric($row['delimiter1'] ?? null) || !is_numeric($row['delimiter2'] ?? null)) return self::unavailable();
            $amount = (float) $row['price'];
            if (!is_finite($amount) || $amount < 0) return self::unavailable();
            if ($carrier->shipping_handling) $amount += (float) Configuration::get('PS_SHIPPING_HANDLING');
            $amount = Tools::convertPrice($amount, Currency::getCurrencyInstance((int) Configuration::get('PS_CURRENCY_DEFAULT')));
            $ranges[] = array('min' => (float) $row['delimiter1'], 'max' => (float) $row['delimiter2'],
                'price_with_tax' => (float) Tools::ps_round($amount * (1 + $tax / 100), Context::getContext()->getComputingPrecision()));
        }
        $prices = array_unique(array_column($ranges, 'price_with_tax'));
        return array('price_with_tax' => $ranges[0]['price_with_tax'], 'price_ranges' => $ranges,
            'price_varies_by_range' => count($prices) > 1);
    }

    private static function unavailable()
    {
        return array('price_with_tax' => null, 'price_ranges' => array(), 'price_varies_by_range' => false);
    }
}
