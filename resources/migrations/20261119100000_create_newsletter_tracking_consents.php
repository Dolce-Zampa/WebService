<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The current consent is kept separate from PrestaShop's newsletter
 * subscription flag: receiving the newsletter and allowing its open pixel
 * are independent choices. The event table preserves the evidence of each
 * later choice or withdrawal.
 */
final class CreateNewsletterTrackingConsents extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $preferences = $prefix . 'newsletter_tracking_consents';
        $events = $prefix . 'newsletter_tracking_consent_events';

        if (!$this->hasTable($preferences)) {
            $this->table($preferences)
                ->addColumn('customer_id', 'biginteger', ['signed' => false])
                ->addColumn('tracking_allowed', 'boolean')
                ->addColumn('selected_at', 'datetime')
                ->addColumn('policy_version', 'string', ['limit' => 64])
                ->addColumn('source', 'string', ['limit' => 64])
                ->addTimestamps()
                ->addIndex(['customer_id'], ['unique' => true, 'name' => 'uniq_newsletter_tracking_customer'])
                ->create();
        }

        if (!$this->hasTable($events)) {
            $this->table($events)
                ->addColumn('customer_id', 'biginteger', ['signed' => false])
                ->addColumn('tracking_allowed', 'boolean')
                ->addColumn('policy_version', 'string', ['limit' => 64])
                ->addColumn('source', 'string', ['limit' => 64])
                ->addColumn('occurred_at', 'datetime')
                ->addColumn('created_at', 'datetime')
                ->addIndex(['customer_id', 'occurred_at'], ['name' => 'idx_newsletter_tracking_consent_history'])
                ->create();
        }
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $events = $prefix . 'newsletter_tracking_consent_events';
        $preferences = $prefix . 'newsletter_tracking_consents';

        if ($this->hasTable($events)) {
            $this->table($events)->drop()->save();
        }
        if ($this->hasTable($preferences)) {
            $this->table($preferences)->drop()->save();
        }
    }
}
