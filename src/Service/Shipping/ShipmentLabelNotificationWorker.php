<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use GuzzleHttp\ClientInterface;
use Illuminate\Database\ConnectionInterface;

/** Sends one label notification per shipment; ambiguous delivery is never retried blindly. */
final class ShipmentLabelNotificationWorker
{
    private const MAX_PDF_BYTES = 5242880;
    private const MAX_TOTAL_BYTES = 4194304;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PacklinkClient $packlink,
        private readonly PrestashopShippingClient $shop,
        private readonly ClientInterface $http,
        private readonly array $paidStates,
        private readonly array $allowedHosts,
    ) {
        if ($paidStates === [] || $allowedHosts === []) {
            throw new \InvalidArgumentException('Label notification requires paid states and allowed PDF hosts.');
        }
        foreach ($paidStates as $state) {
            if (!is_string($state) || $state === '') {
                throw new \InvalidArgumentException('Invalid paid shipment state.');
            }
        }
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)
                || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) {
                throw new \InvalidArgumentException('Invalid allowed label host.');
            }
        }
    }

    public function run(int $limit = 50): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->db->table('packlink_shipments')->where('status', 'created')
            ->where('label_mail_status', 'pending')->whereNotNull('labels_json')
            ->where(function ($q) use ($now): void { $q->whereNull('label_mail_due_at')->orWhere('label_mail_due_at', '<=', $now); })
            ->orderBy('id')->limit(max(1, min(100, $limit)))->get();
        $counts = ['sent' => 0, 'held' => 0, 'deferred' => 0, 'unknown' => 0];
        foreach ($rows as $row) {
            $claimed = $this->db->table('packlink_shipments')->where('id', $row->id)->where('label_mail_status', 'pending')
                ->where(function ($q) use ($now): void { $q->whereNull('label_mail_due_at')->orWhere('label_mail_due_at', '<=', $now); })
                ->update(['label_mail_status' => 'preparing', 'updated_at' => $now]);
            if ($claimed !== 1) {
                continue;
            }
            $seller = (int) ($row->label_seller_id ?? 0);
            if ($seller < 1 || !$this->sellerStillMatches((int) $row->order_id, $seller)) {
                $this->transition((int) $row->id, 'preparing', ['label_mail_status' => 'held', 'label_mail_error' => 'seller_ambiguous']);
                ++$counts['held'];
                continue;
            }
            try {
                // Fetch fresh signed links and current provider state: stored links can expire.
                $shipment = $this->packlink->shipment((string) $row->provider_reference);
                if (($shipment['packlink_reference'] ?? null) !== $row->provider_reference
                    || !in_array($shipment['state'] ?? null, $this->paidStates, true)) {
                    throw new \RuntimeException('Shipment not in a configured paid state.');
                }
                $labels = $this->packlink->labels((string) $row->provider_reference);
                if (!array_is_list($labels) || $labels === [] || count($labels) > 20) {
                    throw new \RuntimeException('Labels unavailable.');
                }
                $attachments = [];
                $total = 0;
                foreach ($labels as $url) {
                    $pdf = $this->download($url);
                    $total += strlen($pdf);
                    if ($total > self::MAX_TOTAL_BYTES) {
                        throw new \RuntimeException('Labels exceed total size limit.');
                    }
                    $attachments[] = base64_encode($pdf);
                }
            } catch (\Throwable $e) {
                $this->transition((int) $row->id, 'preparing', ['label_mail_status' => 'pending', 'label_mail_error' => 'preparation_failed',
                    'label_mail_due_at' => gmdate('Y-m-d H:i:s', time() + 900)]);
                ++$counts['deferred'];
                continue;
            }
            // From this point a lost response may mean the mail was delivered.
            if ($this->transition((int) $row->id, 'preparing', ['label_mail_status' => 'sending', 'label_mail_error' => null]) !== 1) {
                continue;
            }
            try {
                $this->shop->sendLabel((int) $row->order_id, $seller, $attachments);
                $this->transition((int) $row->id, 'sending', ['label_mail_status' => 'sent', 'label_mail_sent_at' => gmdate('Y-m-d H:i:s')]);
                ++$counts['sent'];
            } catch (\Throwable $e) {
                $this->transition((int) $row->id, 'sending', ['label_mail_status' => 'unknown', 'label_mail_error' => 'delivery_outcome_unknown']);
                ++$counts['unknown'];
            }
        }
        return $counts;
    }

    private function sellerStillMatches(int $orderId, int $seller): bool
    {
        $rows = $this->db->table('order_detail as detail')->leftJoin('product as product', 'product.id_product', '=', 'detail.product_id')
            ->where('detail.id_order', $orderId)->get(['product.id_manufacturer']);
        if ($rows->isEmpty()) {
            return false;
        }
        foreach ($rows as $row) {
            if ((int) ($row->id_manufacturer ?? 0) !== $seller) {
                return false;
            }
        }
        return true;
    }

    private function download(mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), $this->allowedHosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \RuntimeException('Unapproved label URL.');
        }
        $response = $this->http->request('GET', $url, ['allow_redirects' => false, 'http_errors' => false,
            'verify' => true, 'timeout' => 30, 'connect_timeout' => 5, 'stream' => true]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Label download failed.');
        }
        $body = $response->getBody();
        $pdf = '';
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '' && !$body->eof()) {
                throw new \RuntimeException('Incomplete label download.');
            }
            $pdf .= $chunk;
            if (strlen($pdf) > self::MAX_PDF_BYTES) {
                throw new \RuntimeException('Label exceeds size limit.');
            }
        }
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('Label is not a PDF.');
        }
        return $pdf;
    }

    private function transition(int $id, string $from, array $values): int
    {
        return $this->db->table('packlink_shipments')->where('id', $id)->where('label_mail_status', $from)
            ->update($values + ['updated_at' => gmdate('Y-m-d H:i:s')]);
    }
}
