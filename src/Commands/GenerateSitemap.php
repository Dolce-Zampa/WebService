<?php

namespace PS\Webservice\Commands;

use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sitemap:generate', description: 'Genera la sitemap del sito web')]
class GenerateSitemap extends Command
{
    public function __construct(private readonly ClientInterface $http, private readonly string $cronUrl)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->cronUrl === '') {
            $output->writeln('Sitemap cron URL is not configured.');
            return Command::FAILURE;
        }

        try {
            $response = $this->http->request('GET', $this->cronUrl, [
                'timeout' => 120,
                'http_errors' => false,
            ]);
            // gsitemap exits with an empty body on completion; it can return HTTP 200
            // for a bad token or an HTML error, and Refresh signals unfinished work.
            if ($response->getStatusCode() !== 200 || $response->hasHeader('Refresh') || trim((string) $response->getBody()) !== '') {
                $output->writeln('Sitemap cron did not confirm completion.');
                return Command::FAILURE;
            }
        } catch (\Throwable $exception) {
            // HTTP exceptions can contain the credential-bearing URL.
            $output->writeln('Sitemap cron request failed.');
            return Command::FAILURE;
        }

        $output->writeln('Sitemap cron returned an accepted response; verify the generated sitemap separately.');
        return Command::SUCCESS;
    }
}
