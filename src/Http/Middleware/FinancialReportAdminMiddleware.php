<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Restricts the global financial ledger report to an explicitly allowed role. */
final class FinancialReportAdminMiddleware implements MiddlewareInterface
{
    /** @param list<string>|null $allowedGroups */
    public function __construct(?array $allowedGroups = null)
    {
        $configured = $_ENV['FINANCIAL_REPORT_ADMIN_GROUPS'] ?? 'admin,administrator,financial-admin';
        $this->allowedGroups = $allowedGroups ?? $this->normaliseGroups($configured);
    }

    /** @var list<string> */
    private array $allowedGroups;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('auth_claims');
        if (!is_array($claims) || !$this->hasAllowedGroup($claims)) {
            return new Response(403, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'data' => ['error' => 'Forbidden'],
            ], JSON_THROW_ON_ERROR));
        }

        return $handler->handle($request);
    }

    /** @param array<string, mixed> $claims */
    private function hasAllowedGroup(array $claims): bool
    {
        $groups = [];
        foreach (['cognito:groups', 'groups', 'custom:role', 'role'] as $claim) {
            if (!array_key_exists($claim, $claims)) {
                continue;
            }
            $value = $claims[$claim];
            $claimGroups = is_array($value) ? $value : (preg_split('/[\\s,]+/', (string) $value) ?: []);
            $groups = array_merge($groups, $claimGroups);
        }

        foreach ($groups as $group) {
            if (in_array(strtolower(trim((string) $group)), $this->allowedGroups, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function normaliseGroups(string $groups): array
    {
        return array_values(array_filter(array_map(
            static fn (string $group): string => strtolower(trim($group)),
            explode(',', $groups),
        )));
    }
}
