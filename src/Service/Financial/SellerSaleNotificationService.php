<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\MailerInterface;

/**
 * Sends one operational sale notification per seller after an order has been
 * authoritatively finalized.  The notification journal is intentionally
 * separate from the financial ledger: delivery attempts are mutable
 * operational state, not accounting facts.
 */
final class SellerSaleNotificationService
{
    private const LOGS = 'seller_sale_notification_logs';

    public function __construct(
        private readonly Manager $db,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * This method is deliberately best-effort: a mail outage must never undo
     * or fail an already-confirmed order. Repeated authoritative events also
     * provide an immediate retry path for previously failed deliveries.
     */
    public function notifyFinalizedSalesForCart(int $cartId, ?DateTimeInterface $finalizedAt = null): void
    {
        try {
            foreach ($this->sellerSalesForCart($cartId, $finalizedAt) as $sale) {
                $this->deliver($sale);
            }
        } catch (\Throwable $e) {
            $this->logWarning('Seller sale notification preparation failed', [
                'cart_id' => $cartId,
                'exception' => $e::class,
            ]);
        }
    }

    /** Retries all failed deliveries without requiring another checkout event. */
    public function retryFailedNotifications(int $limit = 100): void
    {
        $failed = $this->db->table(self::LOGS)
            ->where('status', 'failed')
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->get(['id_order']);

        foreach ($failed as $entry) {
            $this->notifyFinalizedSalesForOrder((int) $entry->id_order);
        }
    }

    private function notifyFinalizedSalesForOrder(int $orderId): void
    {
        try {
            foreach ($this->sellerSalesForOrder($orderId) as $sale) {
                $this->deliver($sale);
            }
        } catch (\Throwable $e) {
            $this->logWarning('Seller sale notification retry preparation failed', [
                'order_id' => $orderId,
                'exception' => $e::class,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function sellerSalesForCart(int $cartId, ?DateTimeInterface $finalizedAt): array
    {
        $orderId = $this->db->table('orders')
            ->where('id_cart', $cartId)
            ->orderByDesc('id_order')
            ->value('id_order');

        return $this->sellerSales(is_numeric($orderId) ? (int) $orderId : null, $finalizedAt);
    }

    /** @return array<int, array<string, mixed>> */
    private function sellerSalesForOrder(mixed $orderId): array
    {
        if (!is_numeric($orderId) || (int) $orderId < 1) {
            return [];
        }

        return $this->sellerSales((int) $orderId, null);
    }

    /** @return array<int, array<string, mixed>> */
    private function sellerSales(?int $orderId, ?DateTimeInterface $finalizedAt): array
    {
        if ($orderId === null || $orderId < 1) {
            return [];
        }

        $rows = $this->db->table('orders as orders')
            ->join('order_detail as detail', 'detail.id_order', '=', 'orders.id_order')
            ->join('product as product', 'product.id_product', '=', 'detail.product_id')
            ->join('manufacturer as seller', 'seller.id_manufacturer', '=', 'product.id_manufacturer')
            ->where('orders.id_order', $orderId)
            ->whereNotNull('seller.email')
            ->select([
                'orders.id_order', 'orders.id_cart', 'orders.id_lang', 'orders.reference', 'orders.date_add',
                'seller.id_manufacturer as seller_id', 'seller.email as seller_email', 'seller.name as seller_name',
                'detail.product_id', 'detail.product_attribute_id', 'detail.product_name', 'detail.product_quantity',
                'detail.total_price_tax_incl', 'detail.unit_price_tax_incl',
            ])
            ->get();

        $variantDetails = $this->variantDetails($rows);
        $customizationDetails = $this->customizationDetails($rows);
        $sales = [];
        foreach ($rows as $row) {
            $sellerId = (int) $row->seller_id;
            $email = trim((string) $row->seller_email);
            if ($sellerId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!isset($sales[$sellerId])) {
                $sales[$sellerId] = [
                    'order_id' => (int) $row->id_order,
                    'order_reference' => (string) $row->reference,
                    // The signed Stripe event time is the authoritative
                    // finalization moment. The order creation time remains a
                    // safe fallback for retry jobs, which never create a new
                    // notification after a successful delivery.
                    'finalized_at' => ($finalizedAt ?? new DateTimeImmutable((string) $row->date_add))
                        ->format('Y-m-d H:i:s'),
                    'seller_id' => $sellerId,
                    'seller_email' => $email,
                    'seller_name' => trim((string) $row->seller_name),
                    'items' => [],
                    'amount' => '0.00',
                ];
            }

            $quantity = max(1, (int) $row->product_quantity);
            $lineAmount = $this->lineAmount($row, $quantity);
            $detailKey = $this->itemDetailKey((int) $row->product_id, (int) $row->product_attribute_id);
            $sales[$sellerId]['items'][] = [
                'name' => (string) $row->product_name,
                'quantity' => $quantity,
                'amount' => $lineAmount,
                'details' => $this->uniqueDetails([
                    ...($variantDetails[(int) $row->product_attribute_id] ?? []),
                    ...($customizationDetails[$detailKey] ?? []),
                ]),
            ];
            $sales[$sellerId]['amount'] = bcadd($sales[$sellerId]['amount'], $lineAmount, 2);
        }

        return array_values($sales);
    }

    /**
     * Returns the ordered combination values (for example, "Colore: Rosso"
     * and "Taglia: M") keyed by product attribute ID.
     *
     * @param iterable<object> $orderRows
     * @return array<int, array<int, string>>
     */
    private function variantDetails(iterable $orderRows): array
    {
        $attributeIds = [];
        $languageId = null;
        foreach ($orderRows as $row) {
            $attributeId = (int) ($row->product_attribute_id ?? 0);
            if ($attributeId > 0) {
                $attributeIds[] = $attributeId;
            }
            if ($languageId === null && (int) ($row->id_lang ?? 0) > 0) {
                $languageId = (int) $row->id_lang;
            }
        }
        $attributeIds = array_values(array_unique($attributeIds));
        if ($attributeIds === [] || $languageId === null) {
            return [];
        }

        $rows = $this->db->table('product_attribute_combination as combination')
            ->join('attribute as attribute_value', 'attribute_value.id_attribute', '=', 'combination.id_attribute')
            ->join('attribute_lang as attribute_language', function ($join) use ($languageId): void {
                $join->on('attribute_language.id_attribute', '=', 'attribute_value.id_attribute')
                    ->where('attribute_language.id_lang', '=', $languageId);
            })
            ->join('attribute_group_lang as attribute_group_language', function ($join) use ($languageId): void {
                $join->on('attribute_group_language.id_attribute_group', '=', 'attribute_value.id_attribute_group')
                    ->where('attribute_group_language.id_lang', '=', $languageId);
            })
            ->whereIn('combination.id_product_attribute', $attributeIds)
            ->orderBy('combination.id_product_attribute')
            ->orderBy('attribute_value.id_attribute_group')
            ->select([
                'combination.id_product_attribute',
                'attribute_group_language.name as group_name',
                'attribute_language.name as attribute_name',
            ])
            ->get();

        $details = [];
        foreach ($rows as $row) {
            $details[(int) $row->id_product_attribute][] = $this->detailLabel(
                (string) $row->group_name,
                (string) $row->attribute_name,
            );
        }

        return array_map(fn (array $values): array => $this->uniqueDetails($values), $details);
    }

    /**
     * Returns the customer-entered text customizations keyed by product and
     * combination. Customization data belongs to the cart even after the
     * order has been created, so the cart ID is used to avoid mixing carts.
     *
     * @param iterable<object> $orderRows
     * @return array<string, array<int, string>>
     */
    private function customizationDetails(iterable $orderRows): array
    {
        $cartId = null;
        $languageId = null;
        foreach ($orderRows as $row) {
            if ($cartId === null && (int) ($row->id_cart ?? 0) > 0) {
                $cartId = (int) $row->id_cart;
            }
            if ($languageId === null && (int) ($row->id_lang ?? 0) > 0) {
                $languageId = (int) $row->id_lang;
            }
        }
        if ($cartId === null || $languageId === null) {
            return [];
        }

        $rows = $this->db->table('customization as customization')
            ->join('customized_data as customization_data', 'customization_data.id_customization', '=', 'customization.id_customization')
            ->join('customization_field as customization_field', 'customization_field.id_customization_field', '=', 'customization_data.index')
            ->leftJoin('customization_field_lang as customization_field_language', function ($join) use ($languageId): void {
                $join->on('customization_field_language.id_customization_field', '=', 'customization_field.id_customization_field')
                    ->where('customization_field_language.id_lang', '=', $languageId);
            })
            ->where('customization.id_cart', $cartId)
            ->where('customization_data.type', 1)
            ->orderBy('customization.id_customization')
            ->orderBy('customization_data.index')
            ->select([
                'customization.id_product',
                'customization.id_product_attribute',
                'customization_field_language.name as field_name',
                'customization_data.value',
            ])
            ->get();

        $details = [];
        foreach ($rows as $row) {
            $details[$this->itemDetailKey((int) $row->id_product, (int) $row->id_product_attribute)][] = $this->detailLabel(
                trim((string) ($row->field_name ?? '')),
                (string) $row->value,
            );
        }

        return array_map(fn (array $values): array => $this->uniqueDetails($values), $details);
    }

    private function itemDetailKey(int $productId, int $productAttributeId): string
    {
        return $productId . ':' . $productAttributeId;
    }

    private function detailLabel(string $label, string $value): string
    {
        $label = trim($label);
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return $label === '' ? $value : $label . ': ' . $value;
    }

    /** @param array<int, string> $details @return array<int, string> */
    private function uniqueDetails(array $details): array
    {
        return array_values(array_unique(array_filter($details, static fn (string $detail): bool => $detail !== '')));
    }

    /** @param object $detail */
    private function lineAmount(object $detail, int $quantity): string
    {
        $amount = $detail->total_price_tax_incl;
        if (!is_numeric($amount)) {
            $amount = is_numeric($detail->unit_price_tax_incl) ? (float) $detail->unit_price_tax_incl * $quantity : 0;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    /** @param array<string, mixed> $sale */
    private function deliver(array $sale): void
    {
        if (!$this->claim($sale)) {
            return;
        }

        try {
            $this->mailer->sendSellerSaleNotification(
                $sale['seller_email'],
                $sale['seller_name'],
                $sale['order_reference'],
                $sale['finalized_at'],
                $sale['items'],
                $sale['amount'],
                'payment_accepted',
            );
            $this->db->table(self::LOGS)
                ->where('id_order', $sale['order_id'])
                ->where('id_manufacturer', $sale['seller_id'])
                ->update(['status' => 'sent', 'sent_at' => new DateTimeImmutable(), 'last_error' => null, 'updated_at' => new DateTimeImmutable()]);
        } catch (\Throwable $e) {
            // Store and log only a stable error category. Exception messages
            // may contain recipient or provider data and must not be retained.
            $this->db->table(self::LOGS)
                ->where('id_order', $sale['order_id'])
                ->where('id_manufacturer', $sale['seller_id'])
                ->update(['status' => 'failed', 'last_error' => $e::class, 'updated_at' => new DateTimeImmutable()]);
            $this->logWarning('Seller sale notification delivery failed', [
                'order_id' => $sale['order_id'],
                'seller_id' => $sale['seller_id'],
                'exception' => $e::class,
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function logWarning(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // A notification failure must remain best-effort even when the
            // application logger has not been initialized yet.
        }
    }

    /** @param array<string, mixed> $sale */
    private function claim(array $sale): bool
    {
        return $this->db->getConnection()->transaction(function () use ($sale): bool {
            $entry = $this->db->table(self::LOGS)
                ->where('id_order', $sale['order_id'])
                ->where('id_manufacturer', $sale['seller_id'])
                ->lockForUpdate()
                ->first();

            if ($entry !== null && in_array($entry->status, ['sent', 'sending'], true)) {
                return false;
            }

            $now = new DateTimeImmutable();
            if ($entry === null) {
                $this->db->table(self::LOGS)->insert([
                    'id_order' => $sale['order_id'],
                    'id_manufacturer' => $sale['seller_id'],
                    'order_reference' => $sale['order_reference'],
                    'status' => 'sending',
                    'attempts' => 1,
                    'last_error' => null,
                    'sent_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return true;
            }

            $this->db->table(self::LOGS)->where('id', $entry->id)->update([
                'status' => 'sending',
                'attempts' => (int) $entry->attempts + 1,
                'last_error' => null,
                'updated_at' => $now,
            ]);
            return true;
        });
    }
}
