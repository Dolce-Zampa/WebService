<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Domain\Object\OrderSession;

/**
 * Stores the checkout facts that are approved before a buyer is redirected to
 * Stripe. The data is encrypted at rest because it includes delivery details;
 * correlation fields are deliberately kept in separate columns so they can be
 * indexed without exposing customer data.
 */
final class CheckoutSnapshotService
{
    private const TABLE = 'stripe_checkout_snapshots';
    private const VERSION = 1;
    private const DEFAULT_COMMISSION_RATE = '20.00';

    public function __construct(private readonly Manager $db)
    {
    }

    /**
     * @param array<string, mixed> $cart
     * @return array{id:string,payload:array<string,mixed>}
     */
    public function create(OrderSession $session, array $cart): array
    {
        $payload = $this->snapshotPayload($session, $cart);
        $plainPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        if (isset($payload['gift_card'])) {
            $existing = $this->db->table(self::TABLE)
                ->where('gift_card_reservation_id', $payload['gift_card']['reservation_id'])->first();
            if ($existing !== null) {
                if (!hash_equals((string) $existing->payload_hash, hash('sha256', $plainPayload))) {
                    throw new \RuntimeException('The gift card reservation belongs to a different checkout.');
                }
                return ['id' => (string) $existing->id, 'payload' => $this->payload($existing)];
            }
        }
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');

        $values = [
            'id' => $id,
            'cart_id' => $payload['cart_id'],
            'checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
            'order_id' => null,
            'transfer_group' => null,
            'currency' => $payload['currency'],
            'payload_ciphertext' => $this->encrypt($plainPayload),
            'payload_hash' => hash('sha256', $plainPayload),
            'verification_payload' => json_encode($this->verificationPayload($payload), JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (isset($payload['gift_card'])) {
            $values['gift_card_reservation_id'] = $payload['gift_card']['reservation_id'];
        }
        $this->db->table(self::TABLE)->insert($values);

        return ['id' => $id, 'payload' => $payload];
    }

    /**
     * Records the Checkout Session at creation time and enforces that a
     * snapshot cannot be rebound to a different Stripe object.
     */
    public function bindStripeSession(string $snapshotId, mixed $checkoutSessionId, mixed $paymentIntentId = null, mixed $transferGroup = null): void
    {
        $snapshotId = $this->snapshotId($snapshotId);
        $sessionId = $this->stripeIdentifier($checkoutSessionId, 'Checkout Session');
        $paymentIntentId = $this->optionalStripeIdentifier($paymentIntentId);
        $transferGroup = $this->optionalTransferGroup($transferGroup);
        $record = $this->record($snapshotId);

        $existingSessionId = $this->optionalStripeIdentifier($record->checkout_session_id ?? null);
        if ($existingSessionId !== null && $existingSessionId !== $sessionId) {
            throw new \RuntimeException('Checkout snapshot is already bound to another Stripe Checkout Session.');
        }
        $existingPaymentIntentId = $this->optionalStripeIdentifier($record->stripe_payment_intent_id ?? null);
        if ($existingPaymentIntentId !== null && $paymentIntentId !== null && $existingPaymentIntentId !== $paymentIntentId) {
            throw new \RuntimeException('Checkout snapshot is already bound to another Stripe payment intent.');
        }

        $values = [
            'checkout_session_id' => $sessionId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($paymentIntentId !== null) {
            $values['stripe_payment_intent_id'] = $paymentIntentId;
        }
        if ($transferGroup !== null) {
            $values['transfer_group'] = $transferGroup;
        }
        $this->db->table(self::TABLE)->where('id', $snapshotId)->update($values);
    }

    /**
     * @return array{id:string,payload:array<string,mixed>}
     */
    public function resolveForStripeSession(string $snapshotId, \Stripe\StripeObject $session): array
    {
        $snapshotId = $this->snapshotId($snapshotId);
        $record = $this->record($snapshotId);
        $data = $session->toArray();
        $sessionId = $this->stripeIdentifier($data['id'] ?? null, 'Checkout Session');
        $paymentIntentId = $this->optionalStripeIdentifier($data['payment_intent'] ?? null);
        $transferGroup = $this->optionalTransferGroup($this->metadata($data)['transfer_group'] ?? null);

        $this->bindStripeSession($snapshotId, $sessionId, $paymentIntentId, $transferGroup);
        $payload = $this->payload($record);

        $currency = strtoupper((string) ($data['currency'] ?? ''));
        $amountTotal = $data['amount_total'] ?? null;
        $expectedStripeCents = $payload['gift_card']['stripe_cents'] ?? $payload['totals']['grand_total_cents'];
        if ($currency !== $payload['currency'] || !is_int($amountTotal) && !(is_string($amountTotal) && ctype_digit($amountTotal))
            || (int) $amountTotal !== $expectedStripeCents) {
            throw new \RuntimeException('Stripe Checkout Session does not match its immutable checkout snapshot.');
        }

        return ['id' => $snapshotId, 'payload' => $payload];
    }

    public function bindOrder(string $snapshotId, int $orderId): void
    {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('A valid order ID is required to bind a checkout snapshot.');
        }
        $record = $this->record($this->snapshotId($snapshotId));
        $existingOrderId = (int) ($record->order_id ?? 0);
        if ($existingOrderId > 0 && $existingOrderId !== $orderId) {
            throw new \RuntimeException('Checkout snapshot is already bound to another order.');
        }
        $this->db->table(self::TABLE)->where('id', $snapshotId)->update([
            'order_id' => $orderId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Record the verified payment before attempting to capture a reservation. */
    public function markGiftCardPaymentVerified(string $snapshotId, ?\Stripe\StripeObject $session = null): array
    {
        $snapshotId = $this->snapshotId($snapshotId);
        $record = $this->record($snapshotId);
        $payload = $this->payload($record);
        $gift = $payload['gift_card'] ?? null;
        $platform = is_array($gift) ? ($gift['platform_cents'] ?? 0) : null;
        $total = $payload['totals']['grand_total_cents'] ?? null;
        if (!is_array($gift) || !is_int($gift['gift_card_cents'] ?? null) || $gift['gift_card_cents'] < 1
            || !is_int($gift['stripe_cents'] ?? null) || $gift['stripe_cents'] < 0
            || !is_int($platform) || $platform < 0 || $platform >= 50
            || ($platform > 0 && $gift['stripe_cents'] !== 0)
            || !is_int($total) || $gift['gift_card_cents'] + $gift['stripe_cents'] + $platform !== $total) {
            throw new \RuntimeException('Snapshot has no valid gift card payment.');
        }
        if ($gift['stripe_cents'] === 0) {
            if ($session !== null) {
                throw new \RuntimeException('A fully covered order cannot use a Stripe payment.');
            }
        } else {
            if ($session === null || (string) ($session->payment_status ?? '') !== 'paid') {
                throw new \RuntimeException('Stripe payment has not been verified.');
            }
            $intent = $session->payment_intent ?? null;
            $intent = $intent instanceof \Stripe\StripeObject ? $intent->id : $intent;
            if (!is_string($intent) || !preg_match('/^pi_[A-Za-z0-9]+$/D', $intent)) {
                throw new \RuntimeException('Stripe payment intent is missing.');
            }
            $this->resolveForStripeSession($snapshotId, $session);
        }
        $this->db->table(self::TABLE)->where('id', $snapshotId)->whereNull('gift_card_released_at')
            ->whereNull('gift_card_payment_verified_at')
            ->update(['gift_card_payment_verified_at' => gmdate('Y-m-d H:i:s'),
                'gift_card_next_recovery_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($this->db->table(self::TABLE)->where('id', $snapshotId)->whereNull('gift_card_released_at')
            ->whereNotNull('gift_card_payment_verified_at')->doesntExist()) {
            throw new \RuntimeException('Gift card checkout was released.');
        }
        return ['id' => $snapshotId, 'payload' => $payload];
    }

    /** @return array{id:string,payload:array<string,mixed>,payment_reference:string,verified_at:string} */
    public function verifiedGiftCardSnapshot(string $snapshotId): array
    {
        $snapshotId = $this->snapshotId($snapshotId);
        $record = $this->record($snapshotId);
        $payload = $this->payload($record);
        if (empty($record->gift_card_payment_verified_at) || empty($payload['gift_card'])
            || ($record->gift_card_reservation_id ?? null) !== ($payload['gift_card']['reservation_id'] ?? null)) {
            throw new \RuntimeException('Gift card payment has not been verified.');
        }
        $gift = $payload['gift_card'];
        $reference = ($gift['stripe_cents'] ?? null) === 0
            ? 'gc_' . $gift['reservation_id'] : (string) ($record->stripe_payment_intent_id ?? '');
        if (!preg_match('/^(?:pi_[A-Za-z0-9]+|gc_[a-f0-9]{32})$/D', $reference)) {
            throw new \RuntimeException('Gift card checkout payment reference is unavailable.');
        }
        return ['id' => $snapshotId, 'payload' => $payload, 'payment_reference' => $reference,
            'verified_at' => (string) $record->gift_card_payment_verified_at];
    }

    /** @return list<string> */
    public function unsettledGiftCardSnapshotIds(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Invalid recovery batch size.');
        }
        return $this->db->table(self::TABLE)->whereNotNull('gift_card_payment_verified_at')
            ->whereNull('gift_card_allocated_at')->where('gift_card_next_recovery_at', '<=', gmdate('Y-m-d H:i:s'))
            ->orderBy('gift_card_next_recovery_at')
            ->limit($limit)->pluck('id')->all();
    }

    public function postponeGiftCardRecovery(string $snapshotId): void
    {
        $this->db->table(self::TABLE)->where('id', $this->snapshotId($snapshotId))
            ->whereNull('gift_card_allocated_at')
            ->update(['gift_card_next_recovery_at' => gmdate('Y-m-d H:i:s', time() + 300)]);
    }

    public function markGiftCardAllocationQueued(string $snapshotId): void
    {
        $updated = $this->db->table(self::TABLE)->where('id', $this->snapshotId($snapshotId))
            ->whereNotNull('gift_card_settled_at')->whereNull('gift_card_allocated_at')
            ->update(['gift_card_allocated_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($updated === 0 && $this->db->table(self::TABLE)->where('id', $snapshotId)
            ->whereNotNull('gift_card_settled_at')->whereNotNull('gift_card_allocated_at')->doesntExist()) {
            throw new \RuntimeException('Gift card order has not been settled.');
        }
    }

    /** @return array{id:string,payload:array<string,mixed>,order_id:?int,settled:bool,verified:bool}|null */
    public function giftCardSnapshotForCart(int $cartId): ?array
    {
        if ($cartId < 1) {
            throw new \InvalidArgumentException('Invalid cart ID.');
        }
        $record = $this->db->table(self::TABLE)->where('cart_id', $cartId)
            ->whereNotNull('gift_card_reservation_id')->whereNull('gift_card_released_at')->orderByDesc('created_at')->first();
        if ($record === null) {
            return null;
        }
        $payload = $this->payload($record);
        $expiresAt = strtotime((string) ($payload['gift_card']['expires_at'] ?? ''));
        if (empty($record->gift_card_payment_verified_at) && $expiresAt !== false && $expiresAt <= time()) {
            return null;
        }
        return ['id' => (string) $record->id, 'payload' => $payload,
            'order_id' => empty($record->order_id) ? null : (int) $record->order_id,
            'settled' => !empty($record->gift_card_settled_at),
            'verified' => !empty($record->gift_card_payment_verified_at)];
    }

    /** Resolve the exact attempt, including a released attempt for safe cancellation retries. */
    public function giftCardSnapshotById(string $snapshotId): ?array
    {
        $record = $this->db->table(self::TABLE)->where('id', $this->snapshotId($snapshotId))
            ->whereNotNull('gift_card_reservation_id')->first();
        if ($record === null) return null;
        return ['id' => (string) $record->id, 'payload' => $this->payload($record),
            'session_id' => $this->optionalStripeIdentifier($record->checkout_session_id ?? null),
            'verified' => !empty($record->gift_card_payment_verified_at),
            'released' => !empty($record->gift_card_released_at)];
    }

    public function markGiftCardReleased(string $snapshotId): void
    {
        $this->db->table(self::TABLE)->where('id', $this->snapshotId($snapshotId))
            ->whereNull('gift_card_payment_verified_at')->whereNull('gift_card_released_at')
            ->update(['gift_card_released_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($this->db->table(self::TABLE)->where('id', $snapshotId)->whereNull('gift_card_payment_verified_at')
            ->whereNotNull('gift_card_released_at')->doesntExist()) {
            throw new \RuntimeException('A paid gift card checkout cannot be released.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(OrderSession $session, array $cart): array
    {
        $sessionData = $session->toArray();
        $metadata = $sessionData['metadata'] ?? [];
        if (!is_array($metadata)) {
            throw new \RuntimeException('Checkout session metadata is invalid.');
        }
        $cartId = $this->positiveInteger($metadata['cart_id'] ?? null, 'cart ID');
        $currency = strtoupper((string) ($cart['currency_iso'] ?? 'EUR'));
        if ($currency !== 'EUR') {
            throw new \InvalidArgumentException('Only EUR checkout snapshots are supported.');
        }
        $products = $cart['products'] ?? null;
        if (!is_array($products) || $products === []) {
            throw new \InvalidArgumentException('Checkout snapshot requires at least one product line.');
        }

        $commissionRates = $this->commissionRates($products);
        $lines = [];
        $sellerTotals = [];
        $productsCents = 0;
        foreach ($products as $product) {
            if (!is_array($product)) {
                throw new \InvalidArgumentException('Checkout snapshot has an invalid cart product.');
            }
            $productId = $this->positiveInteger($product['id_product'] ?? null, 'product ID');
            $quantity = $this->positiveInteger($product['quantity'] ?? null, 'product quantity');
            $unitAmount = $this->minorAmount($product['price_wt'] ?? null, 'product price');
            $totalAmount = $unitAmount * $quantity;
            $sellerId = $this->optionalPositiveInteger($product['id_manufacturer'] ?? null);
            $commission = $sellerId === null ? null : ($commissionRates[$sellerId] ?? $this->defaultCommissionBasisPoints());
            if (StripeConnectModule::enabled() && $sellerId === null) {
                throw new \InvalidArgumentException('Every Connect checkout product must have a seller.');
            }

            $line = [
                'product_id' => $productId,
                'product_attribute_id' => max(0, (int) ($product['id_product_attribute'] ?? 0)),
                'customization_id' => max(0, (int) ($product['id_customization'] ?? 0)),
                'quantity' => $quantity,
                'unit_amount_tax_incl_cents' => $unitAmount,
                'total_amount_tax_incl_cents' => $totalAmount,
                'unit_amount_tax_excl_cents' => $this->optionalMinorAmount($product['price_tax_excl'] ?? null),
                'total_amount_tax_excl_cents' => $this->optionalMinorAmount($product['total_tax_excl'] ?? null),
                'seller_id' => $sellerId,
                'seller_name' => is_string($product['manufacturer_name'] ?? null) ? $product['manufacturer_name'] : null,
                'commission_basis_points' => $commission,
                'name' => (string) ($product['name'] ?? ''),
                'reference' => (string) ($product['reference'] ?? ''),
            ];
            $lines[] = $line;
            $productsCents += $totalAmount;
            if ($sellerId !== null) {
                if (!isset($sellerTotals[$sellerId])) {
                    $sellerTotals[$sellerId] = ['manufacturer_id' => $sellerId, 'gross_cents' => 0, 'commission_basis_points' => $commission];
                }
                if ($sellerTotals[$sellerId]['commission_basis_points'] !== $commission) {
                    throw new \RuntimeException('Seller commission is inconsistent within the checkout snapshot.');
                }
                $sellerTotals[$sellerId]['gross_cents'] += $totalAmount;
            }
        }

        $shippingCents = $this->minorAmount($cart['totals']['shipping_tax_incl'] ?? 0, 'shipping total');
        $grandTotalCents = $this->minorAmount($session->payableTotal(), 'checkout total');
        $discountCents = $productsCents + $shippingCents - $grandTotalCents;
        if ($discountCents < 0) {
            throw new \RuntimeException('Checkout snapshot total is greater than its product and shipping totals.');
        }
        ksort($sellerTotals, SORT_NUMERIC);

        $payload = [
            'version' => self::VERSION,
            'cart_id' => $cartId,
            'customer_id' => $this->optionalPositiveInteger($metadata['id_customer'] ?? null),
            'guest_id' => $this->optionalPositiveInteger($metadata['id_guest'] ?? null),
            'carrier_id' => ($cart['is_virtual'] ?? false) === true && ($metadata['is_virtual'] ?? '') === 'true'
                && (string) ($metadata['id_carrier'] ?? '') === '0'
                ? 0 : $this->positiveInteger($metadata['id_carrier'] ?? null, 'carrier ID'),
            'currency' => $currency,
            'coupon_code' => (string) ($metadata['coupon_code'] ?? ''),
            'payment_module' => isset($metadata['payment_module']) ? (string) $metadata['payment_module'] : null,
            'create_account' => ($metadata['create_account'] ?? 'false') === 'true',
            'newsletter' => ($metadata['newsletter'] ?? 'false') === 'true',
            'customer' => $session->getCustomer()->toArray(),
            'lines' => $lines,
            'sellers' => array_values($sellerTotals),
            'totals' => [
                'products_cents' => $productsCents,
                'shipping_cents' => $shippingCents,
                'discount_cents' => $discountCents,
                'grand_total_cents' => $grandTotalCents,
            ],
        ];
        $tender = $session->giftCardTender();
        if ($tender !== null) {
            if ($tender->cartId !== $cartId || $tender->totalCents !== $grandTotalCents) {
                throw new \RuntimeException('Gift card reservation does not match the checkout snapshot.');
            }
            $payload['gift_card'] = $tender->toArray();
        }
        return $payload;
    }

    /** @param array<int, mixed> $products @return array<int, int> */
    private function commissionRates(array $products): array
    {
        $sellerIds = [];
        foreach ($products as $product) {
            if (is_array($product)) {
                $sellerId = $this->optionalPositiveInteger($product['id_manufacturer'] ?? null);
                if ($sellerId !== null) {
                    $sellerIds[] = $sellerId;
                }
            }
        }
        $sellerIds = array_values(array_unique($sellerIds));
        if ($sellerIds === []) {
            return [];
        }
        $rows = $this->db->table('manufacturer_details')
            ->whereIn('id_manufacturer', $sellerIds)
            ->pluck('commission', 'id_manufacturer');
        $rates = [];
        foreach ($sellerIds as $sellerId) {
            $rates[$sellerId] = $this->commissionBasisPoints((string) ($rows[$sellerId] ?? '0'));
        }
        return $rates;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function verificationPayload(array $payload): array
    {
        $verification = [
            'version' => $payload['version'],
            'cart_id' => $payload['cart_id'],
            'carrier_id' => $payload['carrier_id'],
            'currency' => $payload['currency'],
            'totals' => $payload['totals'],
            'lines' => array_map(static fn (array $line): array => [
                'product_id' => $line['product_id'],
                'product_attribute_id' => $line['product_attribute_id'],
                'customization_id' => $line['customization_id'],
                'quantity' => $line['quantity'],
                'unit_amount_tax_incl_cents' => $line['unit_amount_tax_incl_cents'],
                'total_amount_tax_incl_cents' => $line['total_amount_tax_incl_cents'],
            ], $payload['lines']),
        ];
        if (isset($payload['gift_card'])) {
            $verification['gift_card'] = $payload['gift_card'];
        }
        return $verification;
    }

    /** @return array<string, mixed> */
    private function payload(object $record): array
    {
        $plainPayload = $this->decrypt((string) ($record->payload_ciphertext ?? ''));
        if (!hash_equals((string) ($record->payload_hash ?? ''), hash('sha256', $plainPayload))) {
            throw new \RuntimeException('Checkout snapshot integrity check failed.');
        }
        $payload = json_decode($plainPayload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('Checkout snapshot payload is invalid.');
        }
        return $payload;
    }

    private function record(string $snapshotId): object
    {
        $record = $this->db->table(self::TABLE)->where('id', $snapshotId)->first();
        if ($record === null) {
            throw new \RuntimeException('Checkout snapshot was not found.');
        }
        return $record;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function metadata(array $data): array
    {
        $metadata = $data['metadata'] ?? [];
        if ($metadata instanceof \Stripe\StripeObject) {
            $metadata = $metadata->toArray();
        }
        return is_array($metadata) ? $metadata : [];
    }

    private function snapshotId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid checkout snapshot identifier.');
        }
        return $id;
    }

    private function stripeIdentifier(mixed $value, string $name): string
    {
        $identifier = $this->optionalStripeIdentifier($value);
        if ($identifier === null) {
            throw new \RuntimeException('Stripe ' . $name . ' identifier is invalid.');
        }
        return $identifier;
    }

    private function optionalStripeIdentifier(mixed $value): ?string
    {
        if ($value instanceof \Stripe\StripeObject) {
            $value = $value->id ?? null;
        } elseif (is_array($value)) {
            $value = $value['id'] ?? null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > 191) {
            return null;
        }
        return $value;
    }

    private function optionalTransferGroup(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $value)) {
            throw new \RuntimeException('Stripe Checkout Session has an invalid transfer group.');
        }
        return $value;
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('A valid ' . $name . ' is required for a checkout snapshot.');
        }
        return (int) $value;
    }

    private function optionalPositiveInteger(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false ? null : (int) $value;
    }

    private function minorAmount(mixed $amount, string $name): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw new \InvalidArgumentException('A valid ' . $name . ' is required for a checkout snapshot.');
        }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function optionalMinorAmount(mixed $amount): ?int
    {
        return $amount === null || $amount === '' ? null : $this->minorAmount($amount, 'tax-exclusive product price');
    }

    private function commissionBasisPoints(string $rate): int
    {
        if ($rate === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $rate) || (float) $rate === 0.0) {
            return $this->defaultCommissionBasisPoints();
        }
        $basisPoints = (int) round((float) $rate * 100, 0, PHP_ROUND_HALF_UP);
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new \RuntimeException('Seller commission is invalid for a checkout snapshot.');
        }
        return $basisPoints;
    }

    private function defaultCommissionBasisPoints(): int
    {
        $rate = (string) env('STRIPE_CONNECT_DEFAULT_COMMISSION_RATE', self::DEFAULT_COMMISSION_RATE);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $rate)) {
            throw new \RuntimeException('Default seller commission is invalid for a checkout snapshot.');
        }
        $basisPoints = (int) round((float) $rate * 100, 0, PHP_ROUND_HALF_UP);
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new \RuntimeException('Default seller commission is invalid for a checkout snapshot.');
        }
        return $basisPoints;
    }

    private function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('Unable to encrypt checkout snapshot.');
        }
        return 'encrypted:v1:' . base64_encode($iv . $tag . $cipherText);
    }

    private function decrypt(string $value): string
    {
        $prefix = 'encrypted:v1:';
        if (!str_starts_with($value, $prefix)) {
            throw new \RuntimeException('Checkout snapshot ciphertext is invalid.');
        }
        $payload = base64_decode(substr($value, strlen($prefix)), true);
        if ($payload === false || strlen($payload) < 28) {
            throw new \RuntimeException('Checkout snapshot ciphertext is invalid.');
        }
        $plainText = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if ($plainText === false) {
            throw new \RuntimeException('Unable to decrypt checkout snapshot.');
        }
        return $plainText;
    }

    private function encryptionKey(): string
    {
        $applicationKey = (string) env('APP_KEY', '');
        if ($applicationKey === '') {
            throw new \RuntimeException('APP_KEY is required to encrypt checkout snapshots.');
        }
        return hash('sha256', $applicationKey, true);
    }
}
