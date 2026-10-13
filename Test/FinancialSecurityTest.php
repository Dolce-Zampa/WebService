<?php
declare(strict_types=1);

use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Http\Middleware\FinancialAuthorizationMiddleware;
use PS\Webservice\Service\Financial\FinancialAccessPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class FinancialSecurityTest extends TestCase
{
    public function test_financial_metadata_rejects_cardholder_data_and_provider_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->movement(['card_number' => '4242424242424242']);
    }

    public function test_financial_metadata_accepts_small_reconciliation_identifiers_only(): void
    {
        $movement = $this->movement([
            'stripe_session_id' => 'cs_test_123',
            'stripe_payment_status' => 'paid',
            'cart_id' => '42',
        ]);

        self::assertSame('cs_test_123', $movement->metadata['stripe_session_id']);
    }

    public function test_financial_metadata_rejects_nested_provider_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->movement(['stripe_event' => ['object' => ['customer_email' => 'private@example.test']]]);
    }

    public function test_artisan_scope_does_not_trust_a_route_or_query_identifier(): void
    {
        $request = $this->requestFor('seller-a')->withQueryParams(['artisan_id' => '999']);
        $middleware = new FinancialAuthorizationMiddleware(
            FinancialAccessPolicy::SCOPE_ARTISAN,
            // This stands in for a server-side query of the requested ledger
            // record. It does not inspect user-controlled request values.
            static fn (ServerRequestInterface $request): string => 'seller-b',
        );

        $response = $middleware->process($request, new FinancialTestHandler());

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_artisan_scope_allows_only_the_server_resolved_owner(): void
    {
        $middleware = new FinancialAuthorizationMiddleware(
            FinancialAccessPolicy::SCOPE_ARTISAN,
            static fn (ServerRequestInterface $request): string => 'seller-a',
        );

        $response = $middleware->process($this->requestFor('seller-a'), new FinancialTestHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_marketplace_scope_requires_the_dedicated_financial_admin_group(): void
    {
        $middleware = new FinancialAuthorizationMiddleware(FinancialAccessPolicy::SCOPE_MARKETPLACE);

        self::assertSame(403, $middleware->process($this->requestFor('seller-a'), new FinancialTestHandler())->getStatusCode());
        self::assertSame(204, $middleware->process($this->requestFor('operator', ['financial-admin']), new FinancialTestHandler())->getStatusCode());
    }

    /** @param array<string, mixed> $metadata */
    private function movement(array $metadata): FinancialMovement
    {
        return FinancialMovement::fromArray([
            'type' => 'payment',
            'status' => 'available',
            'amount' => '12.99',
            'currency' => 'EUR',
            'occurred_at' => '2026-10-09T10:00:00+00:00',
            'metadata' => $metadata,
        ]);
    }

    /** @param list<string> $groups */
    private function requestFor(string $sub, array $groups = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/financial/test')
            ->withAttribute('auth_claims', [
                'sub' => $sub,
                'cognito:groups' => $groups,
            ]);
    }
}

final class FinancialTestHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204);
    }
}
