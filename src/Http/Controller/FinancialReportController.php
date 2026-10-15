<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use InvalidArgumentException;
use PS\Webservice\Service\Financial\FinancialReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FinancialReportController
{
    public function __construct(private readonly FinancialReportService $reportService)
    {
    }

    /** GET /api/admin/financial-report */
    public function index(Request $request, Response $response): Response
    {
        try {
            return response($this->reportService->report($request->getQueryParams()));
        } catch (InvalidArgumentException $exception) {
            return response(['error' => $exception->getMessage()], 422);
        }
    }
}
