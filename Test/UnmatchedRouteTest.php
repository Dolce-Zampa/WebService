<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;
use Slim\Factory\AppFactory;
use Slim\Routing\RoutingResults;

final class UnmatchedRouteTest extends TestCase
{
    public function test_unknown_get_does_not_reach_the_password_reset_proxy(): void
    {
        $app = AppFactory::create();
        $employees = new EmployeeFinancialAuthService(new Manager(), 'test-secret');
        $container = new class($employees) {
            public function __construct(private EmployeeFinancialAuthService $employees) {}
            public function get(string $id): EmployeeFinancialAuthService { return $this->employees; }
        };
        require __DIR__ . '/../routes/api.php';

        $resolver = $app->getRouteResolver();
        self::assertSame(RoutingResults::NOT_FOUND, $resolver->computeRoutingResults('/unknown-page', 'GET')->getRouteStatus());
        self::assertSame(RoutingResults::NOT_FOUND, $resolver->computeRoutingResults('/api/unknown', 'GET')->getRouteStatus());
        self::assertSame(RoutingResults::FOUND, $resolver->computeRoutingResults('/api/health', 'GET')->getRouteStatus());
        self::assertSame(RoutingResults::FOUND, $resolver->computeRoutingResults('/api/password-reset', 'POST')->getRouteStatus());
    }
}
