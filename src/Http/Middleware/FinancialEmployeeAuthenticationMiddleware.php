<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/** Accepts only a verified, short-lived PrestaShop employee financial session. */
final class FinancialEmployeeAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly EmployeeFinancialAuthService $employees)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine('Authorization'), $matches) !== 1) {
            return $this->unauthorized();
        }
        try {
            $identity = $this->employees->authenticate($matches[1]);
        } catch (InvalidArgumentException) {
            return $this->unauthorized();
        } catch (RuntimeException) {
            return new Response(503, ['Content-Type' => 'application/json'], json_encode(['error' => 'Financial employee authentication is temporarily unavailable'], JSON_THROW_ON_ERROR));
        }
        return $handler->handle($request->withAttribute('financial_employee', $identity));
    }

    private function unauthorized(): ResponseInterface
    {
        return new Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized financial employee session'], JSON_THROW_ON_ERROR));
    }
}
