<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCustomerAndShippingToFinancialLedger extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';
        $this->table($tableName)
            ->addColumn('customer_id', 'biginteger', ['null' => true, 'signed' => false, 'after' => 'order_id'])
            ->addIndex(['customer_id', 'occurred_at'], ['name' => 'idx_financial_transactions_customer_period'])
            ->update();
        $this->execute(sprintf("ALTER TABLE `%s` MODIFY `type` ENUM('payment','refund','commission','payout','provider_fee','shipping','chargeback','adjustment') NOT NULL", $tableName));
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';
        $this->execute(sprintf("ALTER TABLE `%s` MODIFY `type` ENUM('payment','refund','commission','payout','provider_fee','chargeback','adjustment') NOT NULL", $tableName));
        $table = $this->table($tableName);
        if ($table->hasColumn('customer_id')) {
            $table->removeIndex(['customer_id', 'occurred_at'])->removeColumn('customer_id')->update();
        }
    }
}
