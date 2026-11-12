<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGiftCardCheckoutState extends AbstractMigration
{
    public function up(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_snapshots';
        $this->table($table)
            ->addColumn('gift_card_reservation_id', 'char', ['limit' => 32, 'null' => true])
            ->addColumn('gift_card_payment_verified_at', 'datetime', ['null' => true])
            ->addColumn('gift_card_settled_at', 'datetime', ['null' => true])
            ->addColumn('gift_card_allocated_at', 'datetime', ['null' => true])
            ->addColumn('gift_card_next_recovery_at', 'datetime', ['null' => true])
            ->addColumn('gift_card_released_at', 'datetime', ['null' => true])
            ->addIndex(['gift_card_reservation_id'], ['unique' => true, 'name' => 'uniq_checkout_gift_reservation'])
            ->addIndex(['gift_card_allocated_at', 'gift_card_next_recovery_at'], ['name' => 'idx_checkout_gift_recovery'])
            ->update();
    }

    public function down(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_snapshots';
        $this->table($table)
            ->removeIndexByName('idx_checkout_gift_recovery')
            ->removeIndexByName('uniq_checkout_gift_reservation')
            ->removeColumn('gift_card_settled_at')
            ->removeColumn('gift_card_allocated_at')
            ->removeColumn('gift_card_next_recovery_at')
            ->removeColumn('gift_card_released_at')
            ->removeColumn('gift_card_payment_verified_at')
            ->removeColumn('gift_card_reservation_id')
            ->update();
    }
}
