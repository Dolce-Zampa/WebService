<?php
declare(strict_types=1);

use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Cart;
use PHPUnit\Framework\TestCase;

final class CartServiceCouponsTest extends TestCase
{
    private WebserviceConfig $config;

    protected function setUp(): void
    {
        $this->config = new WebserviceConfig('test-api-key', 'shop.example.com');
    }

    private function mockHttp(): HttpServiceInterface
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('getConfig')->willReturn($this->config);
        return $http;
    }

    private function activeCouponRow(): array
    {
        return [
            'id' => '1',
            'code' => 'SPRING10',
            'name' => 'Sconto Primavera',
            'quantity' => '10',
            'active' => '1',
            'valid_from' => '2000-01-01 00:00:00',
            'valid_to' => '2099-12-31 23:59:59',
            'reduction_percent' => '10.00',
            'reduction_amount' => '0.00',
        ];
    }

    private function inactiveCouponRow(): array
    {
        return array_merge($this->activeCouponRow(), [
            'id' => '2',
            'code' => 'INACTIVE',
            'active' => '0',
        ]);
    }

    private function expiredCouponRow(): array
    {
        return array_merge($this->activeCouponRow(), [
            'id' => '3',
            'code' => 'EXPIRED',
            'valid_to' => '2000-01-01 00:00:00',
        ]);
    }

    private function zeroCouponRow(): array
    {
        return array_merge($this->activeCouponRow(), [
            'id' => '4',
            'code' => 'DEPLETED',
            'quantity' => '0',
        ]);
    }

    // ------------------------------------------------------------------ getFeaturedCoupons

    public function test_get_featured_coupons_returns_only_active_valid_coupons(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with($this->stringContains('/cart_rules?'));
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'cart_rules' => [
                $this->activeCouponRow(),
                $this->inactiveCouponRow(),
                $this->expiredCouponRow(),
                $this->zeroCouponRow(),
            ],
        ]);

        $service = new Cart($http);
        $result = $service->getFeaturedCoupons();

        $this->assertCount(1, $result);
        $this->assertSame('SPRING10', $result->first()->code);
    }

    public function test_get_featured_coupons_returns_empty_collection_when_no_rules(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['cart_rules' => []]);

        $service = new Cart($http);
        $result = $service->getFeaturedCoupons();

        $this->assertCount(0, $result);
    }

    public function test_get_featured_coupons_returns_empty_collection_on_http_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(503);
        $http->method('getBody')->willReturn('Service Unavailable');

        $service = new Cart($http);
        $result = $service->getFeaturedCoupons();

        $this->assertCount(0, $result);
    }

    // ------------------------------------------------------------------ getCouponDetail

    public function test_get_coupon_detail_returns_entity_when_code_matches(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn([
            'cart_rules' => [$this->activeCouponRow()],
        ]);

        $service = new Cart($http);
        $result = $service->getCouponDetail('SPRING10');

        $this->assertNotNull($result);
        $this->assertSame('SPRING10', $result->code);
    }

    public function test_get_coupon_detail_returns_null_when_no_rules_found(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['cart_rules' => []]);

        $service = new Cart($http);
        $result = $service->getCouponDetail('NONEXISTENT');

        $this->assertNull($result);
    }

    public function test_get_coupon_detail_is_case_insensitive(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn([
            'cart_rules' => [$this->activeCouponRow()],
        ]);

        $service = new Cart($http);
        $result = $service->getCouponDetail('spring10');

        $this->assertNotNull($result);
        $this->assertSame('SPRING10', $result->code);
    }

    // ------------------------------------------------------------------ validateCoupon

    public function test_validate_coupon_returns_array_on_success(): void
    {
        $discountData = ['discount' => '10%', 'valid' => true];

        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['data' => $discountData]);

        $service = new Cart($http);
        $encodedCartId = $service->encodeId(42, 'cart');

        $result = $service->validateCoupon('SPRING10', $encodedCartId);

        $this->assertIsArray($result);
        $this->assertSame($discountData, $result);
    }

    public function test_validate_coupon_returns_false_when_response_data_is_null(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willThrowException(new \RuntimeException('connection error'));

        $service = new Cart($http);
        $encodedCartId = $service->encodeId(42, 'cart');

        $result = $service->validateCoupon('BADCODE', $encodedCartId);

        $this->assertFalse($result);
    }
}
