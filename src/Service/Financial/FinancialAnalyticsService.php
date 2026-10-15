<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Repositories\FinancialTransactionRepository;

/** Read-only financial aggregates. Customer identity is deliberately limited to its internal ID. */
final class FinancialAnalyticsService
{
    public function __construct(private readonly FinancialTransactionRepository $transactions)
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function overview(array $input): array
    {
        $filters = $this->filters($input);
        $rows = $this->transactions->financialReportQuery($filters)->get();
        $byCurrency = [];
        $customers = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $type = (string) $row->type;
            $byCurrency[$currency] ??= $this->emptyCurrencySummary($currency);
            $byCurrency[$currency]['movement_count']++;
            $byCurrency[$currency]['net_amount'] = $this->add($byCurrency[$currency]['net_amount'], (string) $row->amount);
            $byCurrency[$currency]['by_type'][$type] = $this->add($byCurrency[$currency]['by_type'][$type] ?? '0', (string) $row->amount);

            if ($row->customer_id === null) {
                continue;
            }
            $customerId = (int) $row->customer_id;
            $key = (string) $customerId;
            $customers[$key] ??= [
                'customer_id' => $customerId,
                'label' => 'Cliente #' . $customerId,
                'order_ids' => [],
                'net_amount_by_currency' => [],
                'last_activity_at' => null,
            ];
            if ($row->order_id !== null) {
                $customers[$key]['order_ids'][(int) $row->order_id] = true;
            }
            $customers[$key]['net_amount_by_currency'][$currency] = $this->add($customers[$key]['net_amount_by_currency'][$currency] ?? '0', (string) $row->amount);
            if ($customers[$key]['last_activity_at'] === null || $row->occurred_at > $customers[$key]['last_activity_at']) {
                $customers[$key]['last_activity_at'] = (string) $row->occurred_at;
            }
        }

        ksort($byCurrency);
        $customerRows = array_values(array_map(static fn (array $customer): array => [
            'customer_id' => $customer['customer_id'],
            'label' => $customer['label'],
            'order_count' => count($customer['order_ids']),
            'net_amount_by_currency' => array_map(
                static fn (string $amount, string $currency): array => ['currency' => $currency, 'amount' => $amount],
                $customer['net_amount_by_currency'],
                array_keys($customer['net_amount_by_currency']),
            ),
            'last_activity_at' => $customer['last_activity_at'],
        ], $customers));
        usort($customerRows, static fn (array $left, array $right): int => $right['order_count'] <=> $left['order_count'] ?: $right['customer_id'] <=> $left['customer_id']);

        return [
            'summary_by_currency' => array_values($byCurrency),
            'customer_count' => count($customerRows),
            'top_customers' => array_slice($customerRows, 0, 10),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, string|int> */
    private function filters(array $input): array
    {
        $filters = [];
        try {
            $timezone = new DateTimeZone((string) ($input['timezone'] ?? 'UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid analytics timezone.', 0, $exception);
        }
        foreach (['from', 'to'] as $name) {
            if (!isset($input[$name]) || $input[$name] === '') {
                continue;
            }
            try {
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$name]) === 1
                    ? new DateTimeImmutable((string) $input[$name] . ' 00:00:00', $timezone)
                    : new DateTimeImmutable((string) $input[$name]);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException('Invalid analytics date filter.', 0, $exception);
            }
            if ($name === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$name]) === 1) {
                $date = $date->modify('+1 day');
            }
            $filters[$name] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw new InvalidArgumentException('The to boundary must be after the from boundary.');
        }
        foreach (['customer_id', 'order_id'] as $name) {
            if (isset($input[$name]) && $input[$name] !== '') {
                if (filter_var($input[$name], FILTER_VALIDATE_INT) === false || (int) $input[$name] < 1) {
                    throw new InvalidArgumentException($name . ' must be a positive integer.');
                }
                $filters[$name] = (int) $input[$name];
            }
        }
        foreach (['status' => FinancialMovement::STATUSES, 'type' => FinancialMovement::TYPES] as $name => $allowed) {
            if (isset($input[$name]) && $input[$name] !== '') {
                if (!in_array($input[$name], $allowed, true)) {
                    throw new InvalidArgumentException('Invalid analytics ' . $name . ' filter.');
                }
                $filters[$name] = (string) $input[$name];
            }
        }
        if (isset($input['provider']) && $input['provider'] !== '') {
            $provider = (string) $input['provider'];
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $provider) !== 1) {
                throw new InvalidArgumentException('Invalid analytics provider filter.');
            }
            $filters['provider'] = $provider;
        }
        return $filters;
    }

    /** @return array{currency: string, movement_count: int, net_amount: string, by_type: array<string, string>} */
    private function emptyCurrencySummary(string $currency): array
    {
        return ['currency' => $currency, 'movement_count' => 0, 'net_amount' => '0', 'by_type' => array_fill_keys(FinancialMovement::TYPES, '0')];
    }

    /** Exact addition for canonical ledger decimal strings. */
    private function add(string $left, string $right): string
    {
        $parts = static function (string $value): array {
            if (preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/', $value, $matches) !== 1) {
                throw new InvalidArgumentException('Ledger amount is not a decimal value.');
            }
            return [$matches[1] === '-' ? -1 : 1, ltrim($matches[2] . ($matches[3] ?? ''), '0') ?: '0', strlen($matches[3] ?? '')];
        };
        [$leftSign, $leftMagnitude, $leftScale] = $parts($left);
        [$rightSign, $rightMagnitude, $rightScale] = $parts($right);
        $scale = max($leftScale, $rightScale);
        $leftMagnitude .= str_repeat('0', $scale - $leftScale);
        $rightMagnitude .= str_repeat('0', $scale - $rightScale);
        $compare = static function (string $one, string $two): int { $one = ltrim($one, '0') ?: '0'; $two = ltrim($two, '0') ?: '0'; return strlen($one) <=> strlen($two) ?: strcmp($one, $two); };
        $add = static function (string $one, string $two): string { $carry = 0; $result = ''; for ($i = 0; $i < max(strlen($one), strlen($two)); $i++) { $sum = (int) ($one[-1 - $i] ?? 0) + (int) ($two[-1 - $i] ?? 0) + $carry; $result = ($sum % 10) . $result; $carry = intdiv($sum, 10); } return ($carry ? (string) $carry : '') . $result; };
        $subtract = static function (string $one, string $two): string { $borrow = 0; $result = ''; for ($i = 0; $i < strlen($one); $i++) { $value = (int) $one[-1 - $i] - (int) ($two[-1 - $i] ?? 0) - $borrow; if ($value < 0) { $value += 10; $borrow = 1; } else { $borrow = 0; } $result = $value . $result; } return ltrim($result, '0') ?: '0'; };
        if ($leftSign === $rightSign) { $sign = $leftSign; $magnitude = $add($leftMagnitude, $rightMagnitude); } elseif ($compare($leftMagnitude, $rightMagnitude) >= 0) { $sign = $leftSign; $magnitude = $subtract($leftMagnitude, $rightMagnitude); } else { $sign = $rightSign; $magnitude = $subtract($rightMagnitude, $leftMagnitude); }
        $magnitude = ltrim($magnitude, '0') ?: '0';
        $formatted = $scale ? str_pad($magnitude, $scale + 1, '0', STR_PAD_LEFT) : $magnitude;
        if ($scale) { $formatted = substr($formatted, 0, -$scale) . '.' . substr($formatted, -$scale); $formatted = rtrim(rtrim($formatted, '0'), '.'); }
        return $magnitude === '0' || $sign > 0 ? $formatted : '-' . $formatted;
    }
}
