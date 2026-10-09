<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

/**
 * Authorization rules for financial views and settlement operations.
 *
 * An artisan can only read records whose owner sub was resolved by the server.
 * A marketplace-wide report/payout operation is opt-in and requires either a
 * Cognito `financial-admin` group or an explicit subject configured in
 * FINANCIAL_ADMIN_SUBS. There is intentionally no "default admin" role.
 */
final class FinancialAccessPolicy
{
    public const SCOPE_ARTISAN = 'artisan';
    public const SCOPE_MARKETPLACE = 'marketplace';

    /** @param array<string, mixed> $claims */
    public function canAccessMarketplace(array $claims): bool
    {
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            return false;
        }

        foreach ($this->configuredAdminSubjects() as $adminSub) {
            if (hash_equals($adminSub, $sub)) {
                return true;
            }
        }

        return in_array('financial-admin', $this->groups($claims), true);
    }

    /** @param array<string, mixed> $claims */
    public function canAccessArtisan(array $claims, string $resourceOwnerSub): bool
    {
        if ($this->canAccessMarketplace($claims)) {
            return true;
        }

        $sub = $claims['sub'] ?? null;
        return is_string($sub)
            && $sub !== ''
            && $resourceOwnerSub !== ''
            && hash_equals($resourceOwnerSub, $sub);
    }

    /** @param array<string, mixed> $claims @return list<string> */
    private function groups(array $claims): array
    {
        $groups = $claims['cognito:groups'] ?? [];
        if (is_string($groups)) {
            $groups = explode(',', $groups);
        }
        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            $groups,
        ), static fn (string $group): bool => $group !== ''));
    }

    /** @return list<string> */
    private function configuredAdminSubjects(): array
    {
        $configured = (string) env('FINANCIAL_ADMIN_SUBS', '');
        if ($configured === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $sub): string => trim($sub),
            explode(',', $configured),
        ), static fn (string $sub): bool => $sub !== ''));
    }
}
