<?php
declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Service\PickupPoints\InpostCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:inpost:sync', description: 'Aggiorna il catalogo dei punti InPost')]
final class SyncInpostPointsCommand extends Command
{
    public function __construct(private readonly InpostCatalog $catalog) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('Punti InPost aggiornati: ' . $this->catalog->sync());
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>Sincronizzazione InPost non riuscita; il catalogo precedente è conservato.</error>');
            return Command::FAILURE;
        }
    }
}
