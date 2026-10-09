<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Operational delivery journal. It intentionally stores no recipient email,
 * customer data, Stripe payload, or payment-card information.
 */
final class CreateSellerSaleNotificationLogsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'seller_sale_notification_logs';
        $this->table($tableName)
            ->addColumn('id_order', 'biginteger', ['signed' => false])
            ->addColumn('id_manufacturer', 'biginteger', ['signed' => false])
            ->addColumn('order_reference', 'string', ['limit' => 64])
            ->addColumn('status', 'enum', ['values' => ['sending', 'sent', 'failed'], 'default' => 'sending'])
            ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('last_error', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['id_order', 'id_manufacturer'], ['unique' => true, 'name' => 'uniq_seller_sale_notification'])
            ->addIndex(['status', 'updated_at'], ['name' => 'idx_seller_sale_notification_retry'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'seller_sale_notification_logs';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
