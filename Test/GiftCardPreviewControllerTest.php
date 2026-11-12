<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\GiftCard\GiftCardCheckoutRateLimiter;
use PS\Webservice\Service\GiftCard\GiftCardClient;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GiftCardPreviewControllerTest extends TestCase
{
    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_preview_checks_credit_without_creating_an_order_or_reserving_funds(): void
    {
        $this->configureEnvironment();
        $orders = $this->createMock(Order::class);
        $cart = CartEntity::create(['id' => 42, 'products' => [['id_product' => 1]]], $orders);
        $orders->expects(self::once())->method('getCartFromId')->with(42, null, 8)->willReturn($cart);
        $payments = $this->createMock(PaymentGatewayInterface::class);
        $payments->expects(self::never())->method('createPaymentSession');
        $handler = new MockHandler([function ($request) {
            self::assertStringContainsString('action=lookup', (string) $request->getUri());
            $payload = json_decode((string) $request->getBody(), true);
            self::assertSame('GC-8DUE-28DF', $payload['code']);
            return new GuzzleResponse(200, [], json_encode(['success' => true, 'data' => ['card' => [
                'status' => 'active', 'expired' => false, 'balance_cents' => 2000, 'available_cents' => 2000, 'currency' => 'EUR',
                'recipient_email' => 'private@example.test', 'id' => 'internal-id',
            ]]]));
        }]);
        $client = new GiftCardClient(new Client(['handler' => HandlerStack::create($handler)]),
            'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api', 'gc_api_' . str_repeat('a', 64));
        $controller = new OrderController($orders, $payments, null, null, fn () => $client, null, new GiftCardCheckoutRateLimiter());
        $request = $this->request(['id_cart' => 42, 'id_guest' => 8, 'gift_card' => 'GC-8DUE-28DF']);
        $response = $controller->previewGiftCard($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $result = json_decode((string) $response->getBody(), true);
        self::assertSame(['available_cents' => 2000, 'currency' => 'EUR'], $result['data']);
        self::assertStringNotContainsString('GC-8DUE-28DF', (string) $response->getBody());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_registered_customer_cannot_preview_using_an_unverified_identity(): void
    {
        $this->configureEnvironment();
        $orders = $this->createMock(Order::class);
        $orders->expects(self::never())->method('getCartFromId');
        $controller = new OrderController($orders, $this->createMock(PaymentGatewayInterface::class), null, null,
            fn () => null, null, new GiftCardCheckoutRateLimiter());
        $response = $controller->previewGiftCard($this->request(['id_cart' => 42, 'id_customer' => 8,
            'gift_card' => 'GC-8DUE-28DF']), $this->createMock(ResponseInterface::class), []);
        self::assertSame(403, $response->getStatusCode());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_preview_does_not_reveal_credit_for_a_cart_not_owned_by_requester(): void
    {
        $this->configureEnvironment();
        $orders = $this->createMock(Order::class);
        $orders->expects(self::once())->method('getCartFromId')->willReturn(null);
        $controller = new OrderController($orders, $this->createMock(PaymentGatewayInterface::class), null, null,
            function () { self::fail('Lookup must not be called for another cart.'); }, null, new GiftCardCheckoutRateLimiter());
        $response = $controller->previewGiftCard($this->request(['id_cart' => 42, 'id_guest' => 8,
            'gift_card' => 'GC-8DUE-28DF']), $this->createMock(ResponseInterface::class), []);
        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider unavailableCards
     */
    public function test_preview_rejects_cards_that_cannot_be_spent(array $card, string $expectedCode): void
    {
        $this->configureEnvironment();
        $orders = $this->createMock(Order::class);
        $orders->method('getCartFromId')->willReturn(CartEntity::create(['id' => 42, 'products' => [['id_product' => 1]]], $orders));
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, [], json_encode(['success' => true, 'data' => ['card' => $card]])),
        ]))]);
        $client = new GiftCardClient($http, 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api',
            'gc_api_' . str_repeat('a', 64));
        $controller = new OrderController($orders, $this->createMock(PaymentGatewayInterface::class), null, null,
            fn () => $client, null, new GiftCardCheckoutRateLimiter());
        $response = $controller->previewGiftCard($this->request(['id_cart' => 42, 'id_guest' => 8,
            'gift_card' => 'GC-8DUE-28DF']), $this->createMock(ResponseInterface::class), []);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($expectedCode, json_decode((string) $response->getBody(), true)['data']['error']['code']);
    }

    public static function unavailableCards(): array
    {
        $active = ['status' => 'active', 'expired' => false, 'balance_cents' => 100, 'available_cents' => 100, 'currency' => 'EUR'];
        return [
            'inactive' => [array_replace($active, ['status' => 'blocked']), 'card_inactive'],
            'expired' => [array_replace($active, ['expired' => true]), 'card_expired'],
            'empty' => [array_replace($active, ['balance_cents' => 0, 'available_cents' => 0]), 'zero_balance'],
            'reserved' => [array_replace($active, ['available_cents' => 0]), 'credit_reserved'],
            'currency' => [array_replace($active, ['currency' => 'USD']), 'currency_mismatch'],
        ];
    }

    private function configureEnvironment(): void
    {
        $_SERVER['GIFT_CARD_CHECKOUT_ENABLED'] = $_ENV['GIFT_CARD_CHECKOUT_ENABLED'] = 'true';
        $_SERVER['APP_DISABLE_CACHE'] = $_ENV['APP_DISABLE_CACHE'] = 'false';
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'testing';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => new Repository(new ArrayStore())]);
    }

    private function request(array $payload): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($payload);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.0.2.1']);
        return $request;
    }
}
