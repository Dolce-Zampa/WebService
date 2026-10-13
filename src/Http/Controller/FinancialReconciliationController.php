<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\Financial\FinancialOrderReconciliationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FinancialReconciliationController
{
    public function __construct(private readonly FinancialOrderReconciliationService $reconciliation)
    {
    }

    public function order(Request $request, Response $response, array $args): Response
    {
        $orderId = filter_var($args['orderId'] ?? null, FILTER_VALIDATE_INT);
        $query = $request->getQueryParams();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($query['per_page'] ?? 50, FILTER_VALIDATE_INT);

        if ($orderId === false || $orderId < 1 || $page === false || $page < 1 || $perPage === false || $perPage < 1 || $perPage > 100) {
            return response(['error' => 'Invalid order ID or pagination parameters.'], 422);
        }

        return response($this->reconciliation->forOrder((int) $orderId, (int) $page, (int) $perPage));
    }
}
