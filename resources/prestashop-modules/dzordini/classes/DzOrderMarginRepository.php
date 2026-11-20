<?php

require_once __DIR__ . '/DzOrderMargin.php';

final class DzOrderMarginRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    private function exists($name)
    {
        return (int) $this->db->getValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . pSQL(_DB_PREFIX_ . $name) . "'") > 0;
    }

    public function orders(array $shops, $languageId)
    {
        $shops = array_filter(array_map('intval', $shops), static function ($id) { return $id > 0; });
        if (!$shops) return [];
        $in = implode(',', $shops);
        $orders = $this->db->executeS('SELECT v.*, c.`iso_code` AS currency, osl.`name` AS state_name
            FROM `' . _DB_PREFIX_ . 'dz_order_margin_view` v
            INNER JOIN `' . _DB_PREFIX_ . 'currency` c ON c.`id_currency` = v.`id_currency`
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = v.`current_state` AND osl.`id_lang` = ' . (int) $languageId . '
            WHERE v.`id_shop` IN (' . $in . ") AND v.`date_add` >= '2026-05-01 00:00:00'
            ORDER BY v.`date_add` DESC, v.`id_order` DESC");
        if (!is_array($orders)) throw new RuntimeException('Impossibile leggere gli ordini.');
        if (!$orders) return [];
        $ids = implode(',', array_map(static function ($row) { return (int) $row['id_order']; }, $orders));
        $lines = $this->db->executeS('SELECT od.`id_order`, od.`total_price_tax_incl`, md.`commission`
            FROM `' . _DB_PREFIX_ . 'order_detail` od
            LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = od.`product_id`
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer_details` md ON md.`id_manufacturer` = p.`id_manufacturer`
            WHERE od.`id_order` IN (' . $ids . ')');
        if (!is_array($lines)) throw new RuntimeException('Impossibile leggere le righe e le commissioni artigiano.');
        $byOrder = [];
        foreach ($lines as $line) $byOrder[(int) $line['id_order']][] = $line;

        $fees = [];
        $refunds = [];
        if ($this->exists('order_slip')) {
            $slips = $this->db->executeS('SELECT DISTINCT `id_order` FROM `' . _DB_PREFIX_ . 'order_slip` WHERE `id_order` IN (' . $ids . ')');
            if (!is_array($slips)) throw new RuntimeException('Impossibile leggere le note di credito.');
            foreach ($slips as $slip) $refunds[(int) $slip['id_order']] = true;
        }
        if ($this->exists('financial_transactions') && $this->exists('financial_transaction_events')) {
            $movements = $this->db->executeS('SELECT t.`order_id`, t.`currency`, t.`type`,
                ROUND(SUM(t.`amount`),2) AS amount
                FROM `' . _DB_PREFIX_ . 'financial_transactions` t
                WHERE t.`order_id` IN (' . $ids . ") AND t.`type` IN ('provider_fee','refund')
                AND COALESCE((SELECT e.`status` FROM `" . _DB_PREFIX_ . "financial_transaction_events` e
                    WHERE e.`financial_transaction_id` = t.`id` AND e.`event_type` IN ('recorded','status_changed')
                    ORDER BY e.`occurred_at` DESC, e.`id` DESC LIMIT 1), t.`status`) IN ('available','paid')
                GROUP BY t.`order_id`, t.`currency`, t.`type`");
            if (!is_array($movements)) throw new RuntimeException('Impossibile leggere il registro finanziario.');
            foreach ($movements as $movement) {
                $key = (int) $movement['order_id'] . ':' . $movement['currency'];
                if ($movement['type'] === 'provider_fee') {
                    // Negative ledger expense becomes a positive displayed cost.
                    $fees[$key] = ltrim((string) $movement['amount'], '-');
                } else {
                    $refunds[$key] = true;
                }
            }
        }
        $cancel = (int) Configuration::get('PS_OS_CANCELED');
        $refund = (int) Configuration::get('PS_OS_REFUND');
        foreach ($orders as &$order) {
            $key = (int) $order['id_order'] . ':' . $order['currency'];
            $fee = $order['fee_override'] !== null ? $order['fee_override'] : ($fees[$key] ?? null);
            $order['fee'] = $fee === null ? null : DzOrderMargin::feeInput($fee);
            $order['fee_source'] = $order['fee_override'] !== null ? 'Manuale' : (isset($fees[$key]) ? 'Registro' : 'Da verificare');
            if (in_array((int) $order['current_state'], [$cancel, $refund], true)) $order['valid'] = 0;
            $order['calculated'] = DzOrderMargin::calculate($order, $byOrder[(int) $order['id_order']] ?? [], $order['fee'], isset($refunds[$key]) || isset($refunds[(int) $order['id_order']]));
        }
        unset($order);
        return $orders;
    }

    public function saveFee($id, array $shops, $value)
    {
        $shops = array_filter(array_map('intval', $shops), static function ($shop) { return $shop > 0; });
        if ($id < 1 || !$shops) return false;
        $owned = $this->db->getValue('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $id . ' AND `id_shop` IN (' . implode(',', $shops) . ')');
        if (!$owned) return false;
        $fee = DzOrderMargin::feeInput($value);
        if ($fee === null) {
            return $this->db->execute('DELETE FROM `' . _DB_PREFIX_ . 'dz_order_margin` WHERE `id_order` = ' . (int) $id);
        }
        return $this->db->execute('INSERT INTO `' . _DB_PREFIX_ . 'dz_order_margin` (`id_order`, `fee_override`) VALUES (' . (int) $id . ", '" . pSQL($fee) . "') ON DUPLICATE KEY UPDATE `fee_override` = VALUES(`fee_override`)");
    }
}
