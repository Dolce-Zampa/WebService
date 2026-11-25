<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Event;

/** Journals verified connected-account payout events separately from order transfers. */
final class StripeConnectPayoutService
{
    private const EVENTS = ['payout.created', 'payout.updated', 'payout.paid', 'payout.failed', 'payout.canceled'];
    private const STATUS_PRIORITY = ['pending' => 0, 'in_transit' => 1, 'paid' => 2, 'canceled' => 3, 'failed' => 4];

    public function __construct(private readonly Manager $db)
    {
    }

    public function recordEvent(Event $event): void
    {
        $type = (string) ($event->type ?? '');
        if (!in_array($type, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported payout event.');
        }
        // Platform payouts do not belong to a connected seller account.
        if (($event->account ?? null) === null) {
            return;
        }
        $accountId = $this->requiredId($event->account, 'acct_');
        $eventId = $this->requiredId($event->id ?? null, 'evt_');
        $payout = $event->data->object ?? null;
        $payoutId = $this->requiredId($payout->id ?? null, 'po_');
        $status = (string) ($payout->status ?? '');
        $amount = $payout->amount ?? null;
        $currency = strtoupper((string) ($payout->currency ?? ''));
        $created = $event->created ?? null;
        if (!isset(self::STATUS_PRIORITY[$status]) || !is_int($amount) || $amount < 0
            || !preg_match('/^[A-Z]{3}$/D', $currency) || !is_int($created) || $created <= 0) {
            throw new InvalidArgumentException('Invalid Stripe payout event.');
        }
        // Terminal event types must not be paired with an unrelated snapshot.
        $expected = ['payout.paid' => 'paid', 'payout.failed' => 'failed', 'payout.canceled' => 'canceled'];
        if (isset($expected[$type]) && $expected[$type] !== $status) {
            throw new InvalidArgumentException('Payout event type and status disagree.');
        }
        $arrival = $payout->arrival_date ?? null;
        if ($arrival !== null && (!is_int($arrival) || $arrival <= 0)) {
            throw new InvalidArgumentException('Invalid payout arrival date.');
        }
        $arrivalAt = $arrival === null ? null : (new DateTimeImmutable('@' . $arrival))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $failure = $payout->failure_code ?? null;
        if ($failure !== null && (!is_string($failure) || strlen($failure) > 100)) {
            throw new InvalidArgumentException('Invalid payout failure code.');
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->getConnection()->transaction(function () use ($accountId, $eventId, $payoutId, $type, $status, $amount, $currency, $created, $arrivalAt, $failure, $now): void {
            $manufacturerId = $this->db->table('stripe_connect_accounts')->where('stripe_account_id', $accountId)->value('manufacturer_id');
            $this->db->table('stripe_connect_payouts')->insertOrIgnore([
                'stripe_account_id' => $accountId, 'stripe_payout_id' => $payoutId,
                'manufacturer_id' => $manufacturerId, 'amount' => $amount, 'currency' => $currency,
                'status' => $status, 'status_source' => 'webhook', 'arrival_date' => $arrivalAt, 'failure_code' => $failure,
                'status_event_created' => $created, 'status_event_id' => $eventId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = $this->db->table('stripe_connect_payouts')
                ->where('stripe_account_id', $accountId)->where('stripe_payout_id', $payoutId)->lockForUpdate()->first();
            if ($row === null || (int) $row->amount !== $amount || $row->currency !== $currency) {
                throw new RuntimeException('Payout identity or amount does not match the original event.');
            }
            $inserted = $this->db->table('stripe_connect_payout_events')->insertOrIgnore([
                'stripe_account_id' => $accountId, 'stripe_payout_id' => $payoutId,
                'stripe_event_id' => $eventId, 'event_type' => $type, 'status' => $status,
                'arrival_date' => $arrivalAt, 'failure_code' => $failure,
                'event_created' => $created, 'received_at' => $now,
            ]);
            if ($inserted === 0) {
                $existing = $this->db->table('stripe_connect_payout_events')->where('stripe_event_id', $eventId)->first();
                if ($existing === null || $existing->stripe_account_id !== $accountId || $existing->stripe_payout_id !== $payoutId) {
                    throw new RuntimeException('Payout event identifier is already associated with a different payout.');
                }
                if ($row->manufacturer_id === null && $manufacturerId !== null) {
                    $this->db->table('stripe_connect_payouts')->where('id', $row->id)->update([
                        'manufacturer_id' => $manufacturerId, 'updated_at' => $now,
                    ]);
                }
                return;
            }
            $newer = $created > (int) $row->status_event_created;
            if ($created === (int) $row->status_event_created) {
                $oldPriority = self::STATUS_PRIORITY[$row->status];
                $newPriority = self::STATUS_PRIORITY[$status];
                $newer = $newPriority > $oldPriority || ($newPriority === $oldPriority && strcmp($eventId, $row->status_event_id) > 0);
            }
            $updates = [];
            if ($newer) {
                $updates = ['status' => $status, 'status_source' => 'webhook', 'arrival_date' => $arrivalAt, 'failure_code' => $failure,
                    'status_event_created' => $created, 'status_event_id' => $eventId, 'updated_at' => $now];
            }
            // An account can arrive before onboarding is recorded locally.
            if ($row->manufacturer_id === null && $manufacturerId !== null) {
                $updates['manufacturer_id'] = $manufacturerId;
                $updates['updated_at'] = $now;
            }
            if ($updates !== []) {
                $this->db->table('stripe_connect_payouts')->where('id', $row->id)->update($updates);
            }
        }, 3);
    }

    public function assertKnownAccount(string $accountId): void
    {
        $this->requiredId($accountId, 'acct_');
        if (!$this->db->table('stripe_connect_accounts')->where('stripe_account_id', $accountId)->exists()) {
            throw new InvalidArgumentException('The Stripe account is not associated with a seller.');
        }
    }

    /** Save an API observation without claiming it is a historical webhook. */
    public function recordSnapshot(string $accountId, \Stripe\Payout $payout, int $observedAt, bool $dryRun = true): bool
    {
        $accountId = $this->requiredId($accountId, 'acct_');
        $payoutId = $this->requiredId($payout->id ?? null, 'po_');
        $amount = $payout->amount ?? null;
        $currency = strtoupper((string) ($payout->currency ?? ''));
        $status = (string) ($payout->status ?? '');
        $arrival = $payout->arrival_date ?? null;
        $failure = $payout->failure_code ?? null;
        if (!is_int($amount) || $amount < 0 || !preg_match('/^[A-Z]{3}$/D', $currency)
            || !isset(self::STATUS_PRIORITY[$status]) || $observedAt <= 0
            || ($arrival !== null && (!is_int($arrival) || $arrival <= 0))
            || ($failure !== null && (!is_string($failure) || strlen($failure) > 100))) {
            throw new InvalidArgumentException('Invalid Stripe payout observation.');
        }
        $manufacturerId = $this->db->table('stripe_connect_accounts')->where('stripe_account_id', $accountId)->value('manufacturer_id');
        if ($manufacturerId === null) {
            throw new InvalidArgumentException('The Stripe account is not associated with a seller.');
        }
        $arrivalAt = $arrival === null ? null : gmdate('Y-m-d H:i:s', $arrival);
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->getConnection()->transaction(function () use ($accountId, $payoutId, $manufacturerId, $amount, $currency, $status, $arrivalAt, $failure, $observedAt, $now, $dryRun): bool {
            $query = $this->db->table('stripe_connect_payouts')->where('stripe_account_id', $accountId)->where('stripe_payout_id', $payoutId);
            $row = $query->lockForUpdate()->first();
            if ($row !== null && ((int) $row->amount !== $amount || $row->currency !== $currency)) {
                throw new RuntimeException('Payout identity or amount does not match the original event.');
            }
            if ($row !== null && $row->manufacturer_id !== null && (int) $row->manufacturer_id !== (int) $manufacturerId) {
                throw new RuntimeException('Payout seller does not match the current account association.');
            }
            $associate = $row !== null && $row->manufacturer_id === null;
            $changed = $row === null || ($row->status !== $status || $row->arrival_date !== $arrivalAt || $row->failure_code !== $failure);
            if ($row !== null && (int) $row->status_event_created >= $observedAt) {
                $changed = false;
            }
            if (!$changed && !$associate) {
                return false;
            }
            if ($dryRun) {
                return true;
            }
            if (!$changed) {
                $this->db->table('stripe_connect_payouts')->where('id', $row->id)->update([
                    'manufacturer_id' => $manufacturerId, 'updated_at' => $now,
                ]);
                $this->db->table('stripe_connect_payout_events')->insert([
                    'stripe_account_id' => $accountId, 'stripe_payout_id' => $payoutId,
                    'stripe_event_id' => null, 'event_type' => 'reconciliation.account_associated',
                    'status' => $row->status, 'arrival_date' => $row->arrival_date,
                    'failure_code' => $row->failure_code, 'event_created' => $observedAt, 'received_at' => $now,
                ]);
                return true;
            }
            $values = [
                'manufacturer_id' => $manufacturerId, 'status' => $status, 'status_source' => 'reconciliation',
                'arrival_date' => $arrivalAt, 'failure_code' => $failure, 'status_event_created' => $observedAt,
                'status_event_id' => '', 'updated_at' => $now,
            ];
            if ($row === null) {
                // A concurrent webhook wins if it inserted the payout first.
                $inserted = $this->db->table('stripe_connect_payouts')->insertOrIgnore($values + [
                    'stripe_account_id' => $accountId, 'stripe_payout_id' => $payoutId,
                    'amount' => $amount, 'currency' => $currency, 'created_at' => $now,
                ]);
                if ($inserted === 0) {
                    return false;
                }
            } else {
                $this->db->table('stripe_connect_payouts')->where('id', $row->id)->update($values);
            }
            $this->db->table('stripe_connect_payout_events')->insert([
                'stripe_account_id' => $accountId, 'stripe_payout_id' => $payoutId,
                'stripe_event_id' => null, 'event_type' => 'reconciliation.snapshot', 'status' => $status,
                'arrival_date' => $arrivalAt, 'failure_code' => $failure, 'event_created' => $observedAt,
                'received_at' => $now,
            ]);
            return true;
        }, 3);
    }

    private function requiredId(mixed $id, string $prefix): string
    {
        if (!is_string($id) || !str_starts_with($id, $prefix) || strlen($id) > 191 || strlen($id) === strlen($prefix)) {
            throw new InvalidArgumentException('Invalid Stripe payout identifier.');
        }
        return $id;
    }
}
