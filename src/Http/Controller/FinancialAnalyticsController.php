<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use InvalidArgumentException;
use PS\Webservice\Service\Financial\FinancialAnalyticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FinancialAnalyticsController
{
    public function __construct(private readonly FinancialAnalyticsService $analytics)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        try { return response($this->analytics->overview($request->getQueryParams())); }
        catch (InvalidArgumentException $exception) { return response(['error' => $exception->getMessage()], 422); }
    }
}
