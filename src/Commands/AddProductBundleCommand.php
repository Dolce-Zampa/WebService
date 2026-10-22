<?php
declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Domain\Models\PS\Products\ProductBundle;
use PS\Webservice\Service\PS\Product as ProductService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:product-bundle:add',
    description: 'Crea o aggiorna un prodotto associato a un bundle'
)]
final class AddProductBundleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('parent-product-id', InputArgument::REQUIRED, 'ID del prodotto che propone il bundle')
            ->addArgument('bundled-product-id', InputArgument::REQUIRED, 'ID del prodotto associato')
            ->addOption('reduction', 'r', InputOption::VALUE_REQUIRED, 'Valore dello sconto', '0')
            ->addOption('reduction-type', null, InputOption::VALUE_REQUIRED, 'Tipo di sconto: amount o percentage', 'amount')
            ->addOption('active-reduction', null, InputOption::VALUE_REQUIRED, 'Attiva lo sconto: true o false', 'true')
            ->addOption('position', 'p', InputOption::VALUE_OPTIONAL, 'Posizione nel bundle; il valore predefinito è l’ultima posizione');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parentProductId = $this->positiveInteger($input->getArgument('parent-product-id'));
        $bundledProductId = $this->positiveInteger($input->getArgument('bundled-product-id'));
        $reduction = $this->nonNegativeNumber($input->getOption('reduction'));
        $reductionType = (string) $input->getOption('reduction-type');
        $activeReduction = $this->booleanValue($input->getOption('active-reduction'));
        $position = $this->position($input->getOption('position'));

        if ($parentProductId === null || $bundledProductId === null) {
            $io->error('Gli ID dei prodotti devono essere interi positivi.');
            return self::INVALID;
        }
        if ($parentProductId === $bundledProductId) {
            $io->error('Un prodotto non può essere associato a sé stesso.');
            return self::INVALID;
        }
        if ($reduction === null) {
            $io->error('La riduzione deve essere un numero maggiore o uguale a zero.');
            return self::INVALID;
        }
        if (!in_array($reductionType, ['amount', 'percentage'], true)) {
            $io->error('Il tipo di riduzione deve essere amount o percentage.');
            return self::INVALID;
        }
        if ($activeReduction === null) {
            $io->error('active-reduction deve essere true o false.');
            return self::INVALID;
        }
        if ($position === false) {
            $io->error('La posizione deve essere un intero maggiore o uguale a zero.');
            return self::INVALID;
        }

        try {
            $bundle = ProductBundle::query()->firstOrNew([
                'parent_product_id' => $parentProductId,
                'bundled_product_id' => $bundledProductId,
            ]);
            $created = !$bundle->exists;
            $bundle->fill([
                'reduction' => $reduction,
                'reduction_type' => $reductionType,
                'active_reduction' => $activeReduction,
                'position' => $position ?? $this->nextPosition($parentProductId),
            ]);
            $bundle->save();

            ProductService::invalidateCacheTags(['product:' . $parentProductId, 'product-catalog']);
        } catch (\Throwable $exception) {
            $io->error('Impossibile salvare il bundle: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $io->success(sprintf(
            '%s associazione %d → %d (riduzione %s %s, attiva: %s, posizione: %d).',
            $created ? 'Creata' : 'Aggiornata',
            $parentProductId,
            $bundledProductId,
            $reduction,
            $reductionType,
            $activeReduction ? 'sì' : 'no',
            $bundle->position,
        ));

        return self::SUCCESS;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    private function booleanValue(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function position(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $position === false ? false : (int) $position;
    }

    private function nextPosition(int $parentProductId): int
    {
        $position = ProductBundle::query()->where('parent_product_id', $parentProductId)->max('position');
        return $position === null ? 0 : (int) $position + 1;
    }
}
