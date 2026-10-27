<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A Checkout bank transfer requires a Stripe Customer.  Keep the association
 * locally so retries reuse the same Customer rather than creating one per
 * Checkout Session.  customer_key is intentionally non-personal data: it is
 * a stable PrestaShop customer or guest identifier.  id_customer is retained
 * for registered customers and references PrestaShop's customer primary key;
 * guest checkouts leave it null and are identified by customer_key.
 */
final class CreateStripeCheckoutCustomersTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_customers';
        $this->table($tableName)
            ->addColumn('id_customer', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('customer_key', 'string', ['limit' => 64])
            ->addColumn('stripe_customer_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['customer_key'], ['unique' => true, 'name' => 'uniq_stripe_checkout_customer_key'])
            ->addIndex(['stripe_customer_id'], ['unique' => true, 'name' => 'uniq_stripe_checkout_customer_id'])
            ->addIndex(['id_customer'], ['name' => 'idx_stripe_checkout_customer_id'])
            ->addForeignKey('id_customer', env('PS_TABLE_PREFIX', '') . 'customer', 'id_customer', ['delete' => 'SET_NULL'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_customers';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
