<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Checkout snapshots preserve the server-quoted basket that was sent to
 * Stripe. Customer data is encrypted in payload_ciphertext; the small,
 * non-personal verification payload lets the PrestaShop module reject a cart
 * that has changed before order creation.
 */
final class CreateStripeCheckoutSnapshotsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_snapshots';
        $this->table($tableName, ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 32])
            ->addColumn('cart_id', 'integer', ['signed' => false])
            ->addColumn('checkout_session_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('order_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('transfer_group', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('payload_ciphertext', 'text')
            ->addColumn('payload_hash', 'char', ['limit' => 64])
            ->addColumn('verification_payload', 'text')
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['checkout_session_id'], ['unique' => true, 'name' => 'uniq_stripe_checkout_snapshot_session'])
            ->addIndex(['stripe_payment_intent_id'], ['unique' => true, 'name' => 'uniq_stripe_checkout_snapshot_intent'])
            ->addIndex(['cart_id', 'created_at'], ['name' => 'idx_stripe_checkout_snapshot_cart'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_snapshots';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
