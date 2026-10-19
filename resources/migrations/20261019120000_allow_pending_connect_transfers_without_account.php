<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A checkout can be paid before its seller starts Stripe Connect onboarding.
 * The allocation is therefore created first and linked to the account later.
 */
final class AllowPendingConnectTransfersWithoutAccount extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_transfers')
            ->changeColumn('stripe_account_id', 'string', ['limit' => 191, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $tableName = str_replace('`', '``', $prefix . 'stripe_connect_transfers');
        $pendingWithoutAccount = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS `count` FROM `%s` WHERE `stripe_account_id` IS NULL',
            $tableName,
        ));
        if ((int) ($pendingWithoutAccount['count'] ?? 0) > 0) {
            throw new \RuntimeException('Cannot roll back while pending Stripe Connect transfers have no account.');
        }
        $this->table($prefix . 'stripe_connect_transfers')
            ->changeColumn('stripe_account_id', 'string', ['limit' => 191, 'null' => false])
            ->update();
    }
}
