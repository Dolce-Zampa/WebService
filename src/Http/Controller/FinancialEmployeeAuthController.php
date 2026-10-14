<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use InvalidArgumentException;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

final class FinancialEmployeeAuthController
{
    public function __construct(private readonly EmployeeFinancialAuthService $employees)
    {
    }

    /** POST /api/admin/auth/login */
    public function login(Request $request, Response $response): Response
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) {
            return response(['error' => 'Email and password are required.'], 422);
        }
        try {
            return response($this->employees->login($input));
        } catch (InvalidArgumentException $exception) {
            return response(['error' => $exception->getMessage()], 401);
        } catch (RuntimeException) {
            return response(['error' => 'Financial employee login is temporarily unavailable.'], 503);
        }
    }
}
