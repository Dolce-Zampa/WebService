<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\PS\PrestashopService;
use PS\Webservice\Service\PS\PsModule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestashopController
{
    private PsModule $prestashopService;
    public function __construct(PsModule $prestashopService)
    {
        $this->service = $prestashopService;
    }

    public function welcomeCoupon(Request $request, Response $response): Response
    {
        $payload = (array) ($request->getParsedBody() ?? []);
        if (empty($payload) || !isset($payload['email']) || !isset($payload['privacy_accepted']) || !$payload['privacy_accepted'] || !isset($payload['source'])) {
            throw new \InvalidArgumentException('Payload is required');
        }

        $response = $this->service->welcomeCoupon($payload);

        if (!$response->failed()) {
            return response(['error' => 'Coupon not found'], 404);
        }

        return response($response->toArray(), 200);
    }

}