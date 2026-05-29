<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePetProfessionalServicesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pet_professional_services');

        $table
            ->addColumn('first_name', 'string', ['limit' => 120])
            ->addColumn('last_name', 'string', ['limit' => 120])
            ->addColumn('company_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('vat_number', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('fiscal_code', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('fiscal_data', 'json', ['null' => true])
            ->addColumn('address', 'string', ['limit' => 255])
            ->addColumn('service_type', 'string', ['limit' => 100])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('media', 'json', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['service_type'])
            ->addIndex(['address'])
            ->create();
    }
}
