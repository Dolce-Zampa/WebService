<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

/** Internal authenticated access to the data needed by the shipping worker. */
class webserviceapipacklinkshippingModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function authenticate()
    {
        parent::authenticate();
        $secret = (string) getenv('PACKLINK_SHOP_SECRET');
        $headers = $this->getRequestHeaders();
        $provided = isset($headers['X-Packlink-Shipping-Secret']) ? (string) $headers['X-Packlink-Shipping-Secret'] : '';
        if (strlen($secret) < 32 || $provided === '' || !hash_equals($secret, $provided)) {
            throw new MlabFactoryApiException('Invalid shipping authentication.', 401);
        }
    }

    protected function handleRequest()
    {
        $this->assertRequestMethod(array('GET', 'POST'));
        if (strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST') {
            $payload = $this->getJsonPayload();
            if (($payload['action'] ?? null) === 'send_label') {
                return $this->sendLabel($payload);
            }
            return $this->applySnapshot($payload);
        }
        $idOrder = (int) Tools::getValue('id_order');
        if ($idOrder > 0) {
            return $this->orderData($idOrder);
        }
        return $this->candidates();
    }

    /** Applies one authoritative provider snapshot while serializing order transitions. */
    private function applySnapshot(array $payload)
    {
        $id = isset($payload['order_id']) ? (int) $payload['order_id'] : 0;
        $reference = $payload['provider_reference'] ?? null;
        $providerState = $payload['provider_state'] ?? null;
        $rank = $payload['rank'] ?? null;
        $stateId = $payload['order_state_id'] ?? null;
        $eligible = $payload['eligible_states'] ?? null;
        $tracking = $payload['tracking_number'] ?? null;
        $trackingUrl = $payload['tracking_url'] ?? null;
        $labels = $payload['labels'] ?? null;
        if ($id <= 0 || !is_string($reference) || $reference === '' || strlen($reference) > 191
            || !is_string($providerState) || $providerState === '' || strlen($providerState) > 100
            || !is_int($rank) || $rank < 0 || !is_int($stateId) || $stateId <= 0
            || !is_array($eligible) || $eligible === [] || count($eligible) > 50
            || ($labels !== null && (!is_array($labels) || count($labels) > 20))
            || ($tracking !== null && (!is_string($tracking) || strlen($tracking) > 191 || !Validate::isTrackingNumber($tracking)))
            || ($trackingUrl !== null && !$this->validUrl($trackingUrl))) {
            throw new MlabFactoryApiException('Invalid shipment snapshot.', 422);
        }
        foreach ($eligible as $state) {
            if (!is_int($state) || $state <= 0) {
                throw new MlabFactoryApiException('Invalid eligible state.', 422);
            }
        }
        foreach ($labels ?? array() as $url) {
            if (!$this->validUrl($url)) {
                throw new MlabFactoryApiException('Invalid label URL.', 422);
            }
        }
        $targetState = new OrderState($stateId);
        if (!Validate::isLoadedObject($targetState)) {
            throw new MlabFactoryApiException('Order state does not exist.', 422);
        }
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new MlabFactoryApiException('Unable to start shipment update.', 500);
        }
        try {
            $rows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'packlink_shipments` WHERE `order_id` = ' . $id . ' LIMIT 1 FOR UPDATE', true, false);
            $row = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
            if (!$row || !hash_equals((string) $row['provider_reference'], $reference) || $row['status'] !== 'created') {
                throw new MlabFactoryApiException('Shipment reference does not match.', 409);
            }
            $orders = $db->executeS('SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $id . ' LIMIT 1 FOR UPDATE', true, false);
            $lockedOrder = is_array($orders) && isset($orders[0]) ? $orders[0] : null;
            if (!$lockedOrder) {
                throw new MlabFactoryApiException('Order not found.', 404);
            }
            if ($row['last_state_rank'] !== null && $rank < (int) $row['last_state_rank']) {
                if (!$db->execute('COMMIT')) {
                    throw new MlabFactoryApiException('Unable to complete shipment update.', 500);
                }
                return array('applied' => false, 'reason' => 'older_state');
            }
            $current = (int) $lockedOrder['current_state'];
            $previous = $row['last_order_state'] === null ? null : (int) $row['last_order_state'];
            if ($current !== $stateId && (($previous === null && !in_array($current, $eligible, true))
                || ($previous !== null && $current !== $previous))) {
                throw new MlabFactoryApiException('Order state changed independently; manual review required.', 409);
            }
            $order = new Order($id);
            $order->current_state = $current;
            if ($tracking !== null && $tracking !== '' && (string) $order->getWsShippingNumber() !== $tracking
                && !$order->setWsShippingNumber($tracking)) {
                throw new MlabFactoryApiException('Unable to update tracking.', 500);
            }
            if ($current !== $stateId) {
                $history = new OrderHistory();
                $history->id_order = $id;
                $history->changeIdOrderState($stateId, $order, !$order->hasInvoice());
                if (!$history->add()) {
                    throw new MlabFactoryApiException('Unable to add order history.', 500);
                }
            }
            $values = array(
                'provider_state' => pSQL($providerState),
                'last_state_rank' => $rank,
                'last_order_state' => $stateId,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            );
            if ($tracking !== null && $tracking !== '') {
                $values['tracking_number'] = pSQL($tracking);
            }
            if ($trackingUrl !== null) {
                $values['tracking_url'] = pSQL($trackingUrl, true);
            }
            if ($labels !== null && $labels !== array()) {
                $values['labels_json'] = pSQL(json_encode(array_values($labels)), true);
            }
            if (!$db->update('packlink_shipments', $values, '`order_id` = ' . $id, 0, true)
                || !$db->execute('COMMIT')) {
                throw new MlabFactoryApiException('Unable to persist shipment.', 500);
            }
            return array('applied' => true);
        } catch (Throwable $exception) {
            $db->execute('ROLLBACK');
            if ($exception instanceof MlabFactoryApiException) {
                throw $exception;
            }
            throw new MlabFactoryApiException('Unable to apply shipment snapshot.', 500);
        }
    }

    /** Recheck the recipient immediately before sending the verified PDF attachments. */
    private function sendLabel(array $payload)
    {
        $id = (int) ($payload['order_id'] ?? 0);
        $seller = (int) ($payload['seller_id'] ?? 0);
        $files = $payload['attachments'] ?? null;
        if ($id < 1 || $seller < 1 || !is_array($files) || count($files) < 1 || count($files) > 20) {
            throw new MlabFactoryApiException('Invalid label notification.', 422);
        }
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT p.`id_manufacturer` FROM `' . _DB_PREFIX_ . 'order_detail` od'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = od.`product_id`'
            . ' WHERE od.`id_order` = ' . $id, true, false);
        if (!is_array($rows) || !$rows) {
            throw new MlabFactoryApiException('Order seller unavailable.', 409);
        }
        foreach ($rows as $row) {
            if ((int) ($row['id_manufacturer'] ?? 0) !== $seller) {
                throw new MlabFactoryApiException('Order seller changed or is ambiguous.', 409);
            }
        }
        $record = $db->getRow('SELECT `label_seller_id`, `label_mail_status` FROM `' . _DB_PREFIX_ . 'packlink_shipments` WHERE `order_id` = ' . $id);
        $manufacturer = new Manufacturer($seller);
        $order = new Order($id);
        if (!$record || (int) $record['label_seller_id'] !== $seller || $record['label_mail_status'] !== 'sending'
            || !Validate::isLoadedObject($manufacturer) || !Validate::isEmail((string) $manufacturer->email)
            || !Validate::isLoadedObject($order)) {
            throw new MlabFactoryApiException('Label notification recipient is invalid.', 409);
        }
        $attachments = array();
        $total = 0;
        foreach ($files as $index => $encoded) {
            $pdf = is_string($encoded) ? base64_decode($encoded, true) : false;
            $total += is_string($pdf) ? strlen($pdf) : 0;
            if ($pdf === false || strlen($pdf) > 5242880 || $total > 4194304 || substr($pdf, 0, 5) !== '%PDF-') {
                throw new MlabFactoryApiException('Invalid label PDF.', 422);
            }
            $attachments[] = array('content' => $pdf, 'name' => 'etichetta-' . ($index + 1) . '.pdf', 'mime' => 'application/pdf');
        }
        if ((int) Configuration::get('PS_MAIL_METHOD') === 3) {
            throw new MlabFactoryApiException('Email is disabled.', 503);
        }
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $vars = array('{seller_name}' => htmlspecialchars((string) $manufacturer->name, ENT_QUOTES, 'UTF-8'),
            '{order_reference}' => htmlspecialchars((string) $order->reference, ENT_QUOTES, 'UTF-8'),
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'));
        if (!Mail::Send($lang, 'packlink_label', 'Etichetta di spedizione ordine ' . $order->reference,
            $vars, $manufacturer->email, $manufacturer->name, null, null, $attachments, null,
            dirname(__FILE__) . '/../../mails/')) {
            throw new MlabFactoryApiException('Unable to send label.', 500);
        }
        return array('sent' => true);
    }

    private function validUrl($url)
    {
        return is_string($url) && strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function candidates()
    {
        $since = (string) Tools::getValue('since');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $since);
        if (!$date || $date->format('Y-m-d H:i:s') !== $since) {
            throw new MlabFactoryApiException('A valid activation date is required.', 422);
        }
        $stateInput = (string) Tools::getValue('states');
        if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $stateInput)) {
            throw new MlabFactoryApiException('Valid eligible states are required.', 422);
        }
        $states = array_map('intval', explode(',', $stateInput));
        if (count($states) > 50 || in_array(0, $states, true)) {
            throw new MlabFactoryApiException('Invalid eligible states.', 422);
        }
        $after = max(0, (int) Tools::getValue('after'));
        $limit = max(1, min(100, (int) Tools::getValue('limit', 50)));
        $sql = 'SELECT o.`id_order` FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' WHERE o.`date_add` >= \'' . pSQL($since) . '\''
            . ' AND o.`current_state` IN (' . implode(',', $states) . ')'
            . ' AND o.`id_order` > ' . $after
            . ' AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'order_detail` od'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = od.`product_id`'
            . ' WHERE od.`id_order` = o.`id_order` AND p.`is_virtual` = 0)'
            . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'packlink_shipments` s WHERE s.`order_id` = o.`id_order`)'
            . ' ORDER BY o.`id_order` ASC LIMIT ' . $limit;
        $rows = Db::getInstance()->executeS($sql, true, false);
        if (!is_array($rows)) {
            throw new MlabFactoryApiException('Unable to read shipping candidates.', 500);
        }
        return array('order_ids' => array_map('intval', array_column($rows, 'id_order')));
    }

    private function orderData($idOrder)
    {
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            throw new MlabFactoryApiException('Order not found.', 404);
        }
        $address = new Address((int) $order->id_address_delivery);
        $customer = new Customer((int) $order->id_customer);
        $carrier = new Carrier((int) $order->id_carrier);
        $currency = new Currency((int) $order->id_currency);
        if (!Validate::isLoadedObject($address) || !Validate::isLoadedObject($customer)
            || !Validate::isLoadedObject($carrier) || !Validate::isLoadedObject($currency)) {
            throw new MlabFactoryApiException('Order shipping data is incomplete.', 422);
        }
        $items = array();
        $sellers = array();
        foreach ($order->getOrderDetailList() as $line) {
            $product = new Product((int) $line['product_id']);
            if (!Validate::isLoadedObject($product)) {
                throw new MlabFactoryApiException('Order product is unavailable.', 422);
            }
            $sellers[] = (int) $product->id_manufacturer;
            if ((bool) $product->is_virtual) {
                continue;
            }
            $items[] = array(
                'quantity' => (int) $line['product_quantity'],
                'weight' => (float) ($line['product_weight'] ?: $product->weight),
                'width' => (float) $product->width,
                'height' => (float) $product->height,
                'length' => (float) $product->depth,
            );
        }
        return array('order' => array(
            'id' => (int) $order->id,
            'date_add' => (string) $order->date_add,
            'current_state' => (int) $order->current_state,
            'is_virtual' => (bool) $order->isVirtual(),
            'carrier_reference' => (int) $carrier->id_reference,
            'currency' => (string) $currency->iso_code,
            'content_value' => (float) $order->total_products_wt,
            'to' => array(
                'name' => (string) $address->firstname, 'surname' => (string) $address->lastname,
                'company' => (string) $address->company, 'street1' => (string) $address->address1,
                'street2' => (string) $address->address2, 'zip_code' => (string) $address->postcode,
                'city' => (string) $address->city, 'country' => (string) Country::getIsoById((int) $address->id_country),
                'phone' => (string) ($address->phone ?: $address->phone_mobile), 'email' => (string) $customer->email,
            ),
            'items' => $items,
            'label_seller_id' => count(array_unique($sellers)) === 1 && $sellers[0] > 0 ? $sellers[0] : null,
            'weight_unit' => (string) Configuration::get('PS_WEIGHT_UNIT'),
            'dimension_unit' => (string) Configuration::get('PS_DIMENSION_UNIT'),
        ));
    }
}
