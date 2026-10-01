<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Domain\Object\WebserviceConfig;

final class CartConfiguratorNotesTest extends TestCase
{
    public function test_new_cart_forwards_notes_with_variant_and_existing_customizations(): void
    {
        $notes = 'Configurazione prodotto — Colori: #fff, #000; Taglia: M';
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('failed')->willReturn(false);
        $http->expects(self::once())->method('invoke')->with('POST', self::callback(function ($payload) use ($notes) {
            $line = $payload['cart']['products'][0];
            self::assertSame($notes, $line['notes']);
            self::assertSame(7, $line['id_product_attribute']);
            self::assertSame([['id_customization_field' => 12, 'value' => 'Luna']], $line['customizations']);
            return true;
        }))->willReturnSelf();
        (new Cart($http))->newCart(['productId' => 42, 'productAttributeId' => 7, 'qty' => 1, 'notes' => $notes, 'customizations' => [['id' => 12, 'value' => 'Luna']]]);
    }

    public function test_update_forwards_notes_and_customization_row_identifier(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('failed')->willReturn(false);
        $http->method('getConfig')->willReturn(new WebserviceConfig('https://example.test'));
        $http->expects(self::once())->method('invoke')->with('POST', self::callback(function ($payload) {
            self::assertSame('Configurazione prodotto — Colori: #f00', $payload['cart']['products'][0]['notes']);
            self::assertSame(23, $payload['cart']['products'][0]['id_customization']);
            return true;
        }))->willReturnSelf();
        (new Cart($http))->updateCart(['productId' => 42, 'productAttributeId' => 7, 'qty' => 1, 'customizationId' => 23, 'notes' => 'Configurazione prodotto — Colori: #f00'], 99, 5, true);
    }
}
