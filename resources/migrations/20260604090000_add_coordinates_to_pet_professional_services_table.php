<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCoordinatesToPetProfessionalServicesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pet_professional_services');

        $table
            ->addColumn('latitude', 'decimal', [
                'precision' => 10,
                'scale' => 7,
                'null' => true,
            ])
            ->addColumn('longitude', 'decimal', [
                'precision' => 10,
                'scale' => 7,
                'null' => true,
            ])
            ->addIndex(['latitude', 'longitude'])
            ->update();
    }
}
