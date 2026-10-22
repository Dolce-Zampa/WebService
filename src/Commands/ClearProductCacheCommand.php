<?php
declare(strict_types=1);

namespace PS\Webservice\Commands;

use Illuminate\Support\Facades\Cache;
use PS\Webservice\Service\PS\Product;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cache:clear-product',
    description: 'Invalida la cache di un singolo prodotto'
)]
final class ClearProductCacheCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('product-id', InputArgument::REQUIRED, 'ID del prodotto di cui invalidare la cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $productId = filter_var($input->getArgument('product-id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($productId === false) {
            $io->error('product-id deve essere un intero positivo.');
            return self::INVALID;
        }

        $productTag = 'product:' . $productId;

        Cache::tags([$productTag])->flush();
        Cache::tags(['product-catalog', 'product-detail'])->flush();
        Product::invalidateCacheTags([$productTag, 'product-catalog']);

        $io->success(sprintf('Cache invalidata per il prodotto %d.', $productId));

        return self::SUCCESS;
    }
}
