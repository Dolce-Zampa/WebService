<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PacklinkInpostGateway;
use PS\Webservice\Service\PacklinkInpostGatewayException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Storefront API for Packlink-managed InPost pickup points.
 *
 * Authentication and cart ownership are checked here before the request is
 * forwarded to the Packlink module through a private signed connection.
 */
final class PacklinkInpostController extends CartController
{
    public function __construct(
        \PS\Webservice\Service\PS\Cart $cartService,
        \PS\Webservice\Repositories\PrestashopRepository $prestashopRepository,
        private readonly PacklinkInpostGateway $gateway,
    ) {
        parent::__construct($cartService, $prestashopRepository);
    }

    public function handle(Request $request, Response $response): Response
    {
        try {
            $payload = $this->requireArrayPayload($request->getParsedBody());
            $ownerContext = $this->resolveOwnerContext($request, $payload);
            if ($ownerContext instanceof Response) {
                return $ownerContext;
            }

            $cartId = $this->positiveInteger($payload['id_cart'] ?? null);
            $carrierId = $this->positiveInteger($payload['id_carrier'] ?? null);
            $method = $payload['method'] ?? null;
            if ($cartId === null || $carrierId === null || !is_string($method)) {
                return response(['error' => 'Cart, carrier and method are required.'], 422);
            }

            $cart = $this->cartService->getCartFromId($cartId, $ownerContext['customerId'], $ownerContext['guestId']);
            if ($cart === null) {
                return response(['error' => 'Cart not found or access denied.'], 404);
            }

            $gatewayPayload = [
                'method' => $method,
                'cartId' => (string) $cartId,
                'carrierId' => (string) $carrierId,
            ];

            if ($method === 'bootstrap' || $method === 'getLocations' || $method === 'getSelection' || $method === 'selectLocation') {
                $gatewayPayload['deliveryAddress'] = $this->deliveryAddress($payload['delivery_address'] ?? null);
            } else {
                return response(['error' => 'Unsupported Packlink operation.'], 400);
            }

            if ($method === 'selectLocation') {
                $pointId = $payload['pointId'] ?? null;
                if (!is_string($pointId) || trim($pointId) === '') {
                    return response(['error' => 'A pickup point is required.'], 422);
                }
                $gatewayPayload['pointId'] = trim($pointId);
            }

            return response($this->gateway->request($gatewayPayload));
        } catch (PacklinkInpostGatewayException $e) {
            return response(['error' => $e->getMessage()], $e->statusCode);
        } catch (\InvalidArgumentException $e) {
            return response(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return response(['error' => 'Packlink pickup points are temporarily unavailable.'], 502);
        }
    }

    /**
     * @return array{address1: string, apartment?: string, city: string, postcode: string, country: string}
     */
    private function deliveryAddress(mixed $input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('A complete delivery address is required.');
        }

        $address = [];
        foreach (['address1', 'city', 'postcode', 'country'] as $field) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('A complete delivery address is required.');
            }
            $address[$field] = trim($value);
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $address['country'])) {
            throw new \InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code.');
        }
        $address['country'] = strtoupper($address['country']);

        if (isset($input['apartment']) && is_string($input['apartment']) && trim($input['apartment']) !== '') {
            $address['apartment'] = trim($input['apartment']);
        }

        return $address;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }
}
