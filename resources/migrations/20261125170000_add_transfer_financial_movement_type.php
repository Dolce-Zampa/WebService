<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTransferFinancialMovementType extends AbstractMigration
{
    public function up(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';
        $this->execute(sprintf("ALTER TABLE `%s` MODIFY `type` ENUM('payment','refund','commission','transfer','payout','provider_fee','shipping','chargeback','adjustment') NOT NULL", $table));
    }

    public function down(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';
        $row = $this->fetchRow(sprintf("SELECT COUNT(*) AS n FROM `%s` WHERE `type` = 'transfer'", $table));
        if ((int) ($row['n'] ?? 0) > 0) {
            throw new RuntimeException('Cannot remove transfer type while transfer movements exist.');
        }
        $this->execute(sprintf("ALTER TABLE `%s` MODIFY `type` ENUM('payment','refund','commission','payout','provider_fee','shipping','chargeback','adjustment') NOT NULL", $table));
    }
}
