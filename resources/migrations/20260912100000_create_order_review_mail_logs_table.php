<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrderReviewMailLogsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = 'fy8ie_order_review_mail_logs';

        if (!$this->hasTable($tableName)) {
            $table = $this->table($tableName);
            $table
                ->addColumn('id_order', 'integer')
                ->addColumn('id_customer', 'integer', ['null' => true])
                ->addColumn('email', 'string', ['limit' => 255])
                ->addColumn('status', 'string', ['limit' => 50, 'default' => 'pending'])
                ->addColumn('sent_at', 'datetime')
                ->addTimestamps()
                ->addIndex(['id_order'], ['unique' => true, 'name' => 'uniq_order_review_mail_id_order'])
                ->addIndex(['id_customer'], ['name' => 'idx_order_review_mail_id_customer'])
                ->create();
        }
    }

    public function down(): void
    {
        $tableName = 'fy8ie_order_review_mail_logs';

        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
