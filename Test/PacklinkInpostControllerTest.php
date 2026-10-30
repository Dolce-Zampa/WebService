<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\PacklinkInpostController;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\PacklinkInpostGateway;
use PS\Webservice\Service\PS\Cart;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PacklinkInpostControllerTest extends TestCase
{
    public function test_bootstrap_checks_cart_owner_then_forwards_a_signed_server_request(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['enabled' => true])),
        ]));
        $stack->push(Middleware::history($history));

        $cartService = $this->cartServiceForOwnedCart(42, 7);
        $controller = new PacklinkInpostController(
            $cartService,
            $this->repositoryForCustomer(7),
            new PacklinkInpostGateway(
                new Client(['handler' => $stack]),
                'https://prestashop.test/module/packlink/headlesslocations',
                'shared-test-secret',
            ),
        );

        $request = $this->requestForCustomer(7, [
            'method' => 'bootstrap',
            'id_cart' => 42,
            'id_carrier' => 12,
            'delivery_address' => [
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
                'country' => 'it',
            ],
        ]);

        $result = $controller->handle($request, $this->createMock(ResponseInterface::class));

        self::assertSame(200, $result->getStatusCode());
        self::assertSame(['enabled' => true], json_decode((string) $result->getBody(), true)['data']);
        self::assertCount(1, $history);

        $gatewayRequest = $history[0]['request'];
        $payload = (string) $gatewayRequest->getBody();
        self::assertSame([
            'method' => 'bootstrap',
            'cartId' => '42',
            'carrierId' => '12',
            'deliveryAddress' => [
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
                'country' => 'IT',
            ],
        ], json_decode($payload, true));
        self::assertSame(
            hash_hmac('sha256', $gatewayRequest->getHeaderLine('X-Packlink-Headless-Timestamp') . '.' . $payload, 'shared-test-secret'),
            $gatewayRequest->getHeaderLine('X-Packlink-Headless-Signature'),
        );
    }

    public function test_it_does_not_contact_packlink_for_a_cart_outside_the_authenticated_customer(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();
        $cartService->expects(self::once())->method('getCartFromId')->with(42, 7, null)->willReturn(null);

        $controller = new PacklinkInpostController(
            $cartService,
            $this->repositoryForCustomer(7),
            new PacklinkInpostGateway(new Client(), 'https://invalid.test', 'shared-test-secret'),
        );

        $result = $controller->handle($this->requestForCustomer(7, [
            'method' => 'getLocations',
            'id_cart' => 42,
            'id_carrier' => 12,
            'delivery_address' => [
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
                'country' => 'IT',
            ],
        ]), $this->createMock(ResponseInterface::class));

        self::assertSame(404, $result->getStatusCode());
    }

    private function cartServiceForOwnedCart(int $cartId, int $customerId): Cart
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();
        $cartService->expects(self::once())->method('getCartFromId')->with($cartId, $customerId, null)
            ->willReturn(CartEntity::create(['id' => $cartId, 'products' => []], $cartService));

        return $cartService;
    }

    private function repositoryForCustomer(int $customerId): PrestashopRepository
    {
        $repository = $this->getMockBuilder(PrestashopRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserIdFromSub'])
            ->getMock();
        $repository->method('findUserIdFromSub')->with('customer-sub')->willReturn($customerId);

        return $repository;
    }

    /** @param array<string, mixed> $payload */
    private function requestForCustomer(int $customerId, array $payload): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $request->method('getParsedBody')->willReturn($payload);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}
