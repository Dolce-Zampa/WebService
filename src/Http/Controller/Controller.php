<?php
namespace PS\Webservice\Http\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Controller {

    public function monitor(Request $request, Response $response)
    {
        return response([
            'success' => true,
            'message' => 'Authentication service is up and running'
        ]);
        
    }

    protected function resolveAuthenticatedCustomerIdUsing(Request $request, callable $resolver): int
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '') {
            throw new \RuntimeException('Unauthorized', 401);
        }

        $customerId = $resolver($sub);
        if (!is_int($customerId) || $customerId <= 0) {
            throw new \RuntimeException('Forbidden', 403);
        }

        return $customerId;
    }

    protected function buildAuthorizationErrorResponse(\Throwable $e): Response
    {
        $status = (int) $e->getCode();
        if ($status < 400 || $status > 599) {
            $status = 401;
        }

        return response(['error' => $e->getMessage()], $status);
    }
}