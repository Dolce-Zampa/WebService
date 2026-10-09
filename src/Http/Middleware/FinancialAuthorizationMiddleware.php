<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use PS\Webservice\Service\Financial\FinancialAccessPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards financial routes after AuthenticationMiddleware.
 *
 * The artisan owner is deliberately supplied by a server-side resolver. Route
 * and query identifiers are never considered proof of ownership, preventing
 * BOLA/IDOR when reports and payouts are introduced.
 */
final class FinancialAuthorizationMiddleware implements MiddlewareInterface
{
    /** @var null|callable(ServerRequestInterface): ?string */
    private $artisanOwnerResolver;

    /**
     * @param null|callable(ServerRequestInterface): ?string $artisanOwnerResolver
     */
    public function __construct(
        private readonly string $scope,
        ?callable $artisanOwnerResolver = null,
        private readonly ?FinancialAccessPolicy $policy = null,
    ) {
        if (!in_array($scope, [FinancialAccessPolicy::SCOPE_ARTISAN, FinancialAccessPolicy::SCOPE_MARKETPLACE], true)) {
            throw new \InvalidArgumentException('Unsupported financial authorization scope.');
        }
        if ($scope === FinancialAccessPolicy::SCOPE_ARTISAN && $artisanOwnerResolver === null) {
            throw new \InvalidArgumentException('An artisan financial route requires a server-side owner resolver.');
        }
        $this->artisanOwnerResolver = $artisanOwnerResolver;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('auth_claims');
        if (!is_array($claims) || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return response(['error' => 'Unauthorized'], 401);
        }

        $policy = $this->policy ?? new FinancialAccessPolicy();
        if ($this->scope === FinancialAccessPolicy::SCOPE_MARKETPLACE) {
            if (!$policy->canAccessMarketplace($claims)) {
                return response(['error' => 'Forbidden'], 403);
            }

            return $handler->handle($request);
        }

        $ownerSub = ($this->artisanOwnerResolver)($request);
        if (!is_string($ownerSub) || $ownerSub === '') {
            // Do not disclose whether another artisan's financial record exists.
            return response(['error' => 'Not found'], 404);
        }
        if (!$policy->canAccessArtisan($claims, $ownerSub)) {
            return response(['error' => 'Not found'], 404);
        }

        return $handler->handle($request);
    }
}
