<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\CouponEntity;
use PS\Webservice\Domain\Models\CouponStorage;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConfigController extends CartController
{

    use UseCache;

    public function makeCartRulesConfig(Request $request, Response $response, array $argv): Response
    {
        $rules = $this->cartService->getFeaturedCoupons();
        $bodyParams = $request->getParsedBody();

        if (!isset($bodyParams['rules'])) {
            throw new \InvalidArgumentException("The 'rules' parameter is mandatory in the request body for this endpoint.");
        }

        $requestedRules = $bodyParams['rules'];
        $coupons = [];
        $storage = JsonDataStorage::coupon();
        foreach ($rules as $key => $rule) {
            if (array_key_exists($rule->code, $requestedRules)) {
                $couponEntity = $rule->toArray();
                $couponEntity['id_relative'] = $requestedRules[$rule->code];

                $coupon = CouponEntity::create($couponEntity, $this->cartService);
                $coupons[] = $coupon->toArray();
                $storage->insert(new CouponStorage($coupon));
            }
        }

        return response($coupons, 201);
    }

    public function makeCarriersConfig(Request $request, Response $response, array $argv): Response
    {
        $carriers = $request->getParsedBody()['carriers'] ?? null;
        $storage = JsonDataStorage::carriers();
        foreach ($carriers as $carrier) {
            $storage->insert($carrier);
        }

        return response($carriers, 201);
    }

    public function clearCache(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        if(isset($queryParams['clear_all']) && $queryParams['clear_all'] == true) {
            $this->flush();
            return response(['message' => 'All cache cleared successfully'], 200);
        }

        foreach ($payload['cache'] as $key => $value) {
            $params = [
                "tags" => $value['tags'] ?? null,
                "key" => $value['key'] ?? null
            ];

            $cacheLey = 'api_cache:' . $params['key'];
            if(empty($params['tags'])) {
                throw new \InvalidArgumentException("The 'tags' parameter is mandatory for each cache entry to clear.");
            }
            
            if(empty($params['key'])) {
                $this->tags($params['tags'])->flushTag();
            }

            // invoke key for generate the cache with the new value
            if ($params['key']) {
                Log::debug("Clearing cache for key: " . $params['key']);
                $client = new \GuzzleHttp\Client();
                $client->request('GET', env('PS_BASE_URL') . $params['key'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . env('API_AUTH_TOKEN'),
                        'Accept' => 'application/json',
                    ],
                ]);
            }

        }

        return response(['message' => 'Cache cleared successfully'], 200);
    }

}