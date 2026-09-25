<?php

namespace PS\Webservice\Commands;

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'sitemap:generate',
    description: 'Genera la sitemap del sito web'
)]
class GenerateSitemap extends Command
{
    protected static $defaultName = 'sitemap:generate';
    protected static $defaultDescription = 'Comando per generare la sitemap del sito web';

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $curl = new Request('GET', 'https://aidyis-prod-backoffice.dolcezampa.com/module/gsitemap/cron?token=37edfcf724&id_shop=1');
        Log::info('Sitemap generated', ['response' => (string) $curl->getBody()]);
        return Command::SUCCESS;
    }
}
