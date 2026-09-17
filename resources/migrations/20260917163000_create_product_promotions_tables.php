<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProductPromotionsTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');

        $packagesTable = $this->table($prefix . 'promotion_packages');
        $packagesTable
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('duration_days', 'integer')
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('position', 'string', ['limit' => 50, 'default' => 'homepage'])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['active'])
            ->addIndex(['position'])
            ->create();

        $promotionsTable = $this->table($prefix . 'product_promotions');
        $promotionsTable
            ->addColumn('product_id', 'integer')
            ->addColumn('seller_id', 'integer')
            ->addColumn('package_id', 'integer')
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('start_date', 'datetime', ['null' => true])
            ->addColumn('end_date', 'datetime', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 50, 'default' => 'pending'])
            ->addColumn('stripe_session_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['seller_id'])
            ->addIndex(['product_id'])
            ->addIndex(['package_id'])
            ->addIndex(['status'])
            ->addIndex(['start_date'])
            ->addIndex(['end_date'])
            ->addIndex(['stripe_session_id'], ['unique' => true])
            ->create();

        $statsTable = $this->table($prefix . 'product_promotion_stats');
        $statsTable
            ->addColumn('promotion_id', 'integer')
            ->addColumn('impressions', 'integer', ['default' => 0])
            ->addColumn('clicks', 'integer', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['promotion_id'], ['unique' => true])
            ->create();

        $this->execute(sprintf(
            "INSERT INTO %spromotion_packages (name, duration_days, price, position, active) VALUES\n            ('Boost 7 giorni', 7, 5.00, 'category', 1),\n            ('Boost 14 giorni', 14, 9.90, 'homepage', 1),\n            ('Vetrina 30 giorni', 30, 19.90, 'product_page', 1)",
            $prefix
        ));
    }
}
