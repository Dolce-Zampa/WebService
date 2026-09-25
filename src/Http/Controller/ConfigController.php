<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\CouponEntity;
use PS\Webservice\Domain\Enums\CategoriesMap;
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
            
            if(empty($params['key']) && !empty($params['tags'])) {
                $this->tags($params['tags'])->flushTag();
            } 
            if(!empty($params['key']) && !empty($params['tags'])) {
                $this->tags(['product-detail','api'])->removeFromCache($params['key']);
            }

            if(isset($value['category']) && !empty($value['category'])) {
                $this->tags([CategoriesMap::getCategory((int) $value['category'])])->flushTag();
            }

        }

        Log::info('Cache cleared successfully ' . json_encode($payload['cache'] ?? []));

        return response(['message' => 'Cache cleared successfully'], 200);
    }

    public function sitemap(Request $request, Response $response, array $argv): Response
    {
        $prestashopSitemap = file_get_contents("https://www.dolcezampa.com/1_it_0_sitemap.xml");
        $response->getBody()->write(str_replace('https://aidyis-prod-backoffice.dolcezampa.com', 'https://www.dolcezampa.com', $prestashopSitemap));
        return $response->withHeader('Content-Type', 'application/xml');
    }

}