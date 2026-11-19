<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\PS\CartUpdateNotFoundException;
use PS\Webservice\Service\PS\PrestashopConnectorException;
use PS\Webservice\Domain\Object\WebserviceConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartMissingRecoveryTest extends TestCase
{
    private function payload(bool $guest = true): array
    {
        return ['action' => 'add', 'productId' => 42, 'productAttributeId' => 7, 'qty' => 2,
            'customizations' => [['id' => 12, 'value' => 'Luna']], 'notes' => 'Su misura',
            'customerId' => 12, 'isGuest' => $guest];
    }

    public function testMissingGuestCartIsRecreatedWithOriginalProductAndOwner(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['data' => ['cart' => ['id' => 999, 'id_guest' => 12, 'products' => []]]]);
        $cart = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->onlyMethods(['updateCart', 'getCartFromId', 'newCart'])->getMock();
        $cart->expects(self::once())->method('updateCart')->willThrowException(new CartUpdateNotFoundException($http));
        $cart->expects(self::once())->method('getCartFromId')->with('885', null, 12)->willReturn(null);
        $cart->expects(self::once())->method('newCart')->with(self::callback(function ($payload) {
            self::assertSame(12, $payload['id_guest']);
            self::assertSame(42, $payload['productId']);
            self::assertSame(7, $payload['productAttributeId']);
            self::assertSame(2, $payload['qty']);
            self::assertSame([['id' => 12, 'value' => 'Luna']], $payload['customizations']);
            self::assertSame('Su misura', $payload['notes']);
            return true;
        }))->willReturn($http);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($this->payload());
        $result = (new CartController($cart))->updateCart($request, $this->createMock(ResponseInterface::class), ['cartId' => '885']);
        self::assertSame(201, $result->getStatusCode());
        self::assertSame(999, json_decode((string) $result->getBody(), true)['data']['id']);
    }

    public function testExistingCartDoesNotTriggerRecreationOnProduct404(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $cart = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->onlyMethods(['updateCart', 'getCartFromId', 'newCart'])->getMock();
        $cart->method('updateCart')->willThrowException(new CartUpdateNotFoundException($http));
        $cart->expects(self::once())->method('getCartFromId')->with('885', 12, null)->willReturn(CartEntity::create(['id' => 885, 'products' => []], $cart));
        $cart->expects(self::never())->method('newCart');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($this->payload(false));
        $this->expectException(CartUpdateNotFoundException::class);
        (new CartController($cart))->updateCart($request, $this->createMock(ResponseInterface::class), ['cartId' => '885']);
    }

    public function testLookupFailureDoesNotCreateCart(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $cart = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->onlyMethods(['updateCart', 'getCartFromId', 'newCart'])->getMock();
        $cart->method('updateCart')->willThrowException(new CartUpdateNotFoundException($http));
        $cart->method('getCartFromId')->willThrowException(new PrestashopConnectorException($http));
        $cart->expects(self::never())->method('newCart');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($this->payload());
        $this->expectException(PrestashopConnectorException::class);
        (new CartController($cart))->updateCart($request, $this->createMock(ResponseInterface::class), ['cartId' => '885']);
    }

    public function testQuantityUpdateDoesNotRecreateCart(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $cart = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->onlyMethods(['updateCart', 'getCartFromId', 'newCart'])->getMock();
        $cart->method('updateCart')->willThrowException(new CartUpdateNotFoundException($http));
        $cart->expects(self::never())->method('newCart');
        $cart->expects(self::never())->method('getCartFromId');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(array_merge($this->payload(), ['action' => 'update']));
        $this->expectException(CartUpdateNotFoundException::class);
        (new CartController($cart))->updateCart($request, $this->createMock(ResponseInterface::class), ['cartId' => '885']);
    }

    public function testUpstreamUpdate404IsDistinguishedFromOtherFailures(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('getConfig')->willReturn(new WebserviceConfig('https://example.test'));
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(404);
        $this->expectException(CartUpdateNotFoundException::class);
        (new Cart($http))->updateCart($this->payload(), 885, 12, true);
    }

    public function testFailedOwnerLookupIsNotMistakenForMissingCart(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(503);
        $this->expectException(PrestashopConnectorException::class);
        (new Cart($http))->getCartFromId(885, null, 12);
    }

    public function testReplacementCartPayloadIncludesGuestAndCustomization(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('failed')->willReturn(false);
        $http->expects(self::once())->method('invoke')->with('POST', self::callback(function ($payload) {
            self::assertSame(12, $payload['cart']['id_guest']);
            self::assertSame([['id_customization_field' => 12, 'value' => 'Luna']], $payload['cart']['products'][0]['customizations']);
            self::assertSame('Su misura', $payload['cart']['products'][0]['notes']);
            return true;
        }))->willReturnSelf();
        (new Cart($http))->newCart(array_merge($this->payload(), ['id_guest' => 12]));
    }
}
