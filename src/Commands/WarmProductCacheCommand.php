<?php
declare(strict_types=1);
namespace PS\Webservice\Commands;

use PS\Webservice\Domain\Models\PS\Products\Product as ProductTable;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\RedisQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:warm-products', description: 'Queue active products missing a complete cache, newest IDs first')]
final class WarmProductCacheCommand extends Command
{
    public function __construct(private Product $products, private RedisQueue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum missing products to enqueue; 0 means all', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = (string) $input->getOption('limit');
        if (!ctype_digit($value)) {
            $output->writeln('<error>limit must be a non-negative integer</error>');
            return self::INVALID;
        }
        if (env('APP_DISABLE_CACHE', false)) {
            $output->writeln('<error>Cache is disabled; warmup skipped.</error>');
            return self::FAILURE;
        }
        $limit = (int) $value;
        $queued = $skipped = 0;
        // Keyset pagination keeps memory bounded and ID order stable while products are created.
        $before = null;
        do {
            $query = ProductTable::where('active', 1)->orderByDesc('id_product')->limit(200);
            if ($before !== null) {
                $query->where('id_product', '<', $before);
            }
            $ids = $query->pluck('id_product');
            foreach ($ids as $id) {
                $id = (int) $id;
                $before = $id;
                if ($this->products->hasCompleteProductCached($id) || !$this->products->reserveWarmup($id)) {
                    ++$skipped;
                    continue;
                }
                try {
                    $this->queue->push(Product::WARM_QUEUE, ['product_id' => $id]);
                } catch (\Throwable $e) {
                    $this->products->releaseWarmup($id);
                    throw $e;
                }
                ++$queued;
                if ($limit > 0 && $queued >= $limit) {
                    break 2;
                }
            }
        } while ($ids->count() === 200);
        $output->writeln(sprintf('Queued: %d; already cached or pending: %d.', $queued, $skipped));
        return self::SUCCESS;
    }
}
