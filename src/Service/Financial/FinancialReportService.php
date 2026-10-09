<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Repositories\FinancialTransactionRepository;

/** Read-only, administrator-facing view of the immutable financial ledger. */
final class FinancialReportService
{
    public function __construct(private readonly FinancialTransactionRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function report(array $input): array
    {
        $timezone = $this->timezone($input['timezone'] ?? 'UTC');
        $filters = $this->filters($input, $timezone);
        $page = $this->positiveInteger($input['page'] ?? 1, 'page');
        $perPage = $this->perPage($input['per_page'] ?? 50);

        $baseQuery = $this->repository->financialReportQuery($filters);
        $totalItems = (int) (clone $baseQuery)->count();
        $rows = (clone $baseQuery)
            ->orderByDesc('financial_transaction.occurred_at')
            ->orderByDesc('financial_transaction.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => $this->movement($row))
            ->all();

        // Aggregate values use the original decimal strings and exact integer
        // arithmetic, rather than PHP floats or database-specific SUM casts.
        $totals = [];
        foreach ((clone $baseQuery)->get(['transaction.currency', 'transaction.amount']) as $row) {
            $currency = (string) $row->currency;
            $totals[$currency] = $this->addDecimal($totals[$currency] ?? '0', (string) $row->amount);
        }
        ksort($totals);

        return [
            'data' => $rows,
            'summary' => [
                'movement_count' => $totalItems,
                'net_amount_by_currency' => array_map(
                    static fn (string $amount, string $currency): array => ['currency' => $currency, 'amount' => $amount],
                    $totals,
                    array_keys($totals),
                ),
            ],
            'filters' => [
                'from_inclusive' => $filters['from'] ?? null,
                'to_exclusive' => $filters['to'] ?? null,
                'timezone' => $timezone->getName(),
                'artisan_id' => $filters['artisan_id'] ?? null,
                'order_id' => $filters['order_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'provider' => $filters['provider'] ?? null,
                'type' => $filters['type'] ?? null,
            ],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => (int) ceil($totalItems / $perPage),
                'has_next_page' => $page * $perPage < $totalItems,
                'has_previous_page' => $page > 1,
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, string|int> */
    private function filters(array $input, DateTimeZone $timezone): array
    {
        $filters = [];
        if (array_key_exists('from', $input) && $input['from'] !== '') {
            $filters['from'] = $this->boundary((string) $input['from'], $timezone, false);
        }
        if (array_key_exists('to', $input) && $input['to'] !== '') {
            $filters['to'] = $this->boundary((string) $input['to'], $timezone, true);
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] >= $filters['to']) {
            throw new InvalidArgumentException('The to boundary must be after the from boundary.');
        }
        foreach (['artisan_id', 'order_id'] as $name) {
            if (array_key_exists($name, $input) && $input[$name] !== '') {
                $filters[$name] = $this->positiveInteger($input[$name], $name);
            }
        }
        if (array_key_exists('status', $input) && $input['status'] !== '') {
            $filters['status'] = $this->enum((string) $input['status'], FinancialMovement::STATUSES, 'status');
        }
        if (array_key_exists('type', $input) && $input['type'] !== '') {
            $filters['type'] = $this->enum((string) $input['type'], FinancialMovement::TYPES, 'type');
        }
        if (array_key_exists('provider', $input) && $input['provider'] !== '') {
            $provider = (string) $input['provider'];
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $provider)) {
                throw new InvalidArgumentException('Invalid provider filter.');
            }
            $filters['provider'] = $provider;
        }
        return $filters;
    }

    private function timezone(mixed $value): DateTimeZone
    {
        try {
            return new DateTimeZone((string) $value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid timezone.', 0, $exception);
        }
    }

    private function boundary(string $value, DateTimeZone $timezone, bool $endOfDate): string
    {
        try {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? new DateTimeImmutable($value . ' 00:00:00', $timezone)
                : new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid period boundary.', 0, $exception);
        }
        if ($endOfDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = $date->modify('+1 day');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $name));
        }
        return (int) $value;
    }

    private function perPage(mixed $value): int
    {
        $perPage = $this->positiveInteger($value, 'per_page');
        if ($perPage > 100) {
            throw new InvalidArgumentException('per_page cannot exceed 100.');
        }
        return $perPage;
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed, string $name): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Invalid %s filter.', $name));
        }
        return $value;
    }

    /** @return array<string, int|string|null> */
    private function movement(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'order_id' => $row->order_id === null ? null : (int) $row->order_id,
            'artisan_id' => $row->artisan_id === null ? null : (int) $row->artisan_id,
            'order_reference' => $row->order_reference,
            'artisan_reference' => $row->artisan_reference,
            'type' => (string) $row->type,
            'status' => (string) $row->current_status,
            'amount' => $this->canonicalDecimal((string) $row->amount),
            'currency' => (string) $row->currency,
            'provider' => $row->provider,
            'occurred_at' => (string) $row->occurred_at,
            'available_at' => $row->available_at,
            'settled_at' => $row->settled_at,
        ];
    }

    /** Exact addition for decimal ledger values with up to six fractional digits. */
    private function addDecimal(string $left, string $right): string
    {
        [$leftSign, $leftDigits, $leftScale] = $this->decimalParts($left);
        [$rightSign, $rightDigits, $rightScale] = $this->decimalParts($right);
        $scale = max($leftScale, $rightScale);
        $leftDigits .= str_repeat('0', $scale - $leftScale);
        $rightDigits .= str_repeat('0', $scale - $rightScale);
        if ($leftSign === $rightSign) {
            $digits = $this->addUnsigned($leftDigits, $rightDigits);
            $sign = $leftSign;
        } elseif ($this->compareUnsigned($leftDigits, $rightDigits) >= 0) {
            $digits = $this->subtractUnsigned($leftDigits, $rightDigits);
            $sign = $leftSign;
        } else {
            $digits = $this->subtractUnsigned($rightDigits, $leftDigits);
            $sign = $rightSign;
        }
        $digits = ltrim($digits, '0') ?: '0';
        $result = $scale === 0 ? $digits : str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        if ($scale > 0) {
            $result = substr($result, 0, -$scale) . '.' . substr($result, -$scale);
        }
        return ($sign < 0 && $digits !== '0' ? '-' : '') . $result;
    }

    /** @return array{int, string, int} */
    private function decimalParts(string $value): array
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Ledger amount is not a decimal value.');
        }
        return [$matches[1] === '-' ? -1 : 1, ltrim($matches[2] . ($matches[3] ?? ''), '0') ?: '0', strlen($matches[3] ?? '')];
    }

    private function canonicalDecimal(string $value): string
    {
        [$sign, $digits, $scale] = $this->decimalParts($value);
        $value = $scale === 0 ? $digits : str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        if ($scale > 0) {
            $value = substr($value, 0, -$scale) . '.' . substr($value, -$scale);
        }
        return ($sign < 0 && $digits !== '0' ? '-' : '') . $value;
    }

    private function addUnsigned(string $left, string $right): string
    {
        $carry = 0; $result = '';
        for ($i = 0; $i < max(strlen($left), strlen($right)); $i++) {
            $sum = ((int) ($left[-1 - $i] ?? 0)) + ((int) ($right[-1 - $i] ?? 0)) + $carry;
            $result = ($sum % 10) . $result; $carry = intdiv($sum, 10);
        }
        return ($carry > 0 ? (string) $carry : '') . $result;
    }

    private function compareUnsigned(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0'; $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function subtractUnsigned(string $left, string $right): string
    {
        $borrow = 0; $result = '';
        for ($i = 0; $i < strlen($left); $i++) {
            $difference = (int) $left[-1 - $i] - (int) ($right[-1 - $i] ?? 0) - $borrow;
            if ($difference < 0) { $difference += 10; $borrow = 1; } else { $borrow = 0; }
            $result = $difference . $result;
        }
        return ltrim($result, '0') ?: '0';
    }
}
