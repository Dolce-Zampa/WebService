<?php

declare(strict_types=1);

namespace PS\Webservice\Service;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeps the current newsletter open-pixel preference and an append-only
 * record of the choices made in the customer area.
 */
final class NewsletterTrackingConsentService
{
    private const SOURCE = 'account_privacy';
    private const POLICY_VERSION = 'newsletter-pixel-v1';

    private string $preferencesTable;
    private string $eventsTable;

    public function __construct(
        private ConnectionInterface $connection,
        private MailjetService $mailjet,
        ?string $preferencesTable = null,
        ?string $eventsTable = null,
    ) {
        $this->preferencesTable = $preferencesTable ?? 'newsletter_tracking_consents';
        $this->eventsTable = $eventsTable ?? 'newsletter_tracking_consent_events';
    }

    /**
     * Returns null until the customer has made an explicit choice.
     */
    public function preferenceForCustomer(int $customerId): ?bool
    {
        $value = $this->connection->table($this->preferencesTable)
            ->where('customer_id', $customerId)
            ->value('tracking_allowed');

        return $value === null ? null : (bool) $value;
    }

    /**
     * Mailjet is updated first so a failed provider update cannot leave the
     * local account UI claiming that a refusal is already being applied.
     */
    public function recordChoice(int $customerId, string $email, bool $trackingAllowed): void
    {
        $this->synchronizePreference($email, $trackingAllowed);

        $now = Carbon::now();
        $current = [
            'tracking_allowed' => $trackingAllowed,
            'selected_at' => $now,
            'policy_version' => self::POLICY_VERSION,
            'source' => self::SOURCE,
            'updated_at' => $now,
        ];

        $this->connection->transaction(function () use ($customerId, $trackingAllowed, $now, $current): void {
            $preferences = $this->connection->table($this->preferencesTable)
                ->where('customer_id', $customerId);

            if ($preferences->exists()) {
                $preferences->update($current);
            } else {
                $this->connection->table($this->preferencesTable)->insert(
                    ['customer_id' => $customerId, 'created_at' => $now] + $current,
                );
            }

            $this->connection->table($this->eventsTable)->insert([
                'customer_id' => $customerId,
                'tracking_allowed' => $trackingAllowed,
                'policy_version' => self::POLICY_VERSION,
                'source' => self::SOURCE,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        });
    }

    /**
     * Carries an existing preference to a replacement account email without
     * treating the address change as a new consent action.
     */
    public function synchronizePreference(string $email, bool $trackingAllowed): void
    {
        $this->mailjet->setOpenTrackingConsent($email, $trackingAllowed);
    }
}
