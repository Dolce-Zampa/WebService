<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A Checkout bank transfer requires a Stripe Customer.  Keep the association
 * locally so retries reuse the same Customer rather than creating one per
 * Checkout Session.  customer_key is intentionally non-personal data: it is
 * a stable PrestaShop customer or guest identifier.
 */
final class CreateStripeCheckoutCustomersTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_checkout_customers';
        $this->table($tableName)
            ->addColumn('customer_key', 'string', ['limit' => 64])
            ->addColumn('stripe_customer_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['customer_key'], ['unique' => true, 'name' => 'uniq_stripe_checkout_customer_key'])
            ->addIndex(['stripe_customer_id'], ['unique' => true, 'name' => 'uniq_stripe_checkout_customer_id'])
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
