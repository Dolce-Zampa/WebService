<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use InvalidArgumentException;
use PS\Webservice\Service\Financial\FinancialExpenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FinancialExpenseController
{
    public function __construct(private readonly FinancialExpenseService $expenses)
    {
    }

    public function create(Request $request, Response $response): Response
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) return response(['error' => 'A JSON expense body is required.'], 422);
        try { return response($this->expenses->record($input), 201); }
        catch (InvalidArgumentException $exception) { return response(['error' => $exception->getMessage()], 422); }
    }
}
