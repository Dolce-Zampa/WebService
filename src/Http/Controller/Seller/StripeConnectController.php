<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller\Seller;

use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;
use PS\Webservice\Service\Payments\StripeConnectService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Seller-scoped Connect endpoints. The seller id is always derived from JWT. */
final class StripeConnectController
{
    public function __construct(private readonly StripeConnectService $connect)
    {
    }

    public function onboarding(Request $request, Response $response, array $args): Response
    {
        try {
            $seller = $this->authenticatedSeller($request);
            $payload = $request->getParsedBody();
            $payload = is_array($payload) ? $payload : [];
            $returnUrl = (string) ($payload['return_url'] ?? env('STRIPE_CONNECT_RETURN_URL', ''));
            $refreshUrl = (string) ($payload['refresh_url'] ?? env('STRIPE_CONNECT_REFRESH_URL', ''));
            return response($this->connect->beginOnboarding(
                (int) $seller->getAttribute('id_manufacturer'),
                (string) $seller->getAttribute('email'),
                $returnUrl,
                $refreshUrl,
            ), 201);
        } catch (\InvalidArgumentException $exception) {
            return response(['error' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response(['error' => $exception->getMessage()], 409);
        }
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        try {
            $seller = $this->authenticatedSeller($request);
            return response($this->connect->status((int) $seller->getAttribute('id_manufacturer')));
        } catch (\RuntimeException $exception) {
            return response(['error' => $exception->getMessage()], 404);
        }
    }

    private function authenticatedSeller(Request $request): Manufacturer
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '') {
            throw new \RuntimeException('Unauthorized seller.');
        }
        $seller = Manufacturer::query()->where('sub', $sub)->first();
        if ($seller === null) {
            throw new \RuntimeException('Seller not found.');
        }
        return $seller;
    }
}
