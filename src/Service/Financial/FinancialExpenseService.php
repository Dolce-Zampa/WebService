<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use InvalidArgumentException;
use PS\Webservice\Domain\Financial\FinancialMovement;

/** Records actual operational costs as immutable negative ledger movements. */
final class FinancialExpenseService
{
    public function __construct(private readonly FinancialLedgerService $ledger, private readonly Manager $db)
    {
    }

    /** @param array<string, mixed> $input @return array{transaction_id: int, created: bool} */
    public function record(array $input): array
    {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['shipping', 'provider_fee', 'adjustment'], true)) {
            throw new InvalidArgumentException('Expense type must be shipping, provider_fee or adjustment.');
        }
        $amount = trim((string) ($input['amount'] ?? ''));
        if (preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $amount) !== 1 || preg_match('/^0(?:\.0+)?$/', $amount) === 1) {
            throw new InvalidArgumentException('Expense amount must be a positive decimal string.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Expense currency must be an ISO 4217 code.');
        }
        try { $occurredAt = new DateTimeImmutable((string) ($input['occurred_at'] ?? 'now')); } catch (\Throwable $exception) { throw new InvalidArgumentException('Invalid expense date.', 0, $exception); }
        $orderId = $this->positiveId($input['order_id'] ?? null, 'order_id');
        $order = $orderId === null ? null : $this->db->table('orders')->where('id_order', $orderId)->first();
        if ($orderId !== null && $order === null) {
            throw new InvalidArgumentException('Order not found.');
        }
        $reference = isset($input['reference']) ? trim((string) $input['reference']) : '';
        if (strlen($reference) > 120) {
            throw new InvalidArgumentException('Expense reference is too long.');
        }
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException('A valid idempotency key is required.');
        }
        $metadata = ['expense_source' => 'admin_dashboard'];
        if ($reference !== '') { $metadata['expense_reference'] = $reference; }
        $result = $this->ledger->record(FinancialMovement::fromArray([
            'type' => $type, 'status' => 'paid', 'amount' => '-' . $amount, 'currency' => $currency,
            'occurred_at' => $occurredAt, 'order_id' => $orderId,
            'customer_id' => $order === null || (int) $order->id_customer < 1 ? null : (int) $order->id_customer,
            'order_reference' => $order?->reference ?: null,
            'source_event_type' => 'admin.expense', 'source_event_id' => $idempotencyKey, 'metadata' => $metadata,
        ]), $idempotencyKey);
        return ['transaction_id' => $result->transactionId, 'created' => $result->created];
    }

    private function positiveId(mixed $value, string $name): ?int
    {
        if ($value === null || $value === '') return null;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) throw new InvalidArgumentException($name . ' must be a positive integer.');
        return (int) $value;
    }
}
