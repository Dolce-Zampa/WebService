<?php

namespace PS\Webservice\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:example',
    description: 'Comando di esempio per la CLI Symfony Console'
)]
class ExampleCommand extends Command
{
    protected static $defaultName = 'app:example';
    protected static $defaultDescription = 'Comando di esempio per la CLI Symfony Console';

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument('name', InputArgument::OPTIONAL, 'Nome da salutare', 'World')
            ->addOption('uppercase', 'u', InputOption::VALUE_NONE, 'Trasforma l\'output in maiuscolo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        $message = "Hello, {$name}!";
        if ($input->getOption('uppercase')) {
            $message = strtoupper($message);
        }

        $io->success($message);

        return Command::SUCCESS;
    }
}
