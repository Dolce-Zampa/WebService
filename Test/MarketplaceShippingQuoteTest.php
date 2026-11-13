<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MarketplaceShippingQuoteTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_quote_rolls_back_temporary_addresses_and_cart_changes(): void
    {
        require __DIR__ . '/stubs/shipping-quote-runtime.php';
        require __DIR__ . '/../resources/prestashop-modules/webserviceapi/controllers/front/shippingquote.php';
        $controller = new class extends webserviceapishippingquoteModuleFrontController {
            public function __construct() { $this->context = (object) ['shop' => (object) ['id' => 1]]; $this->module = new stdClass(); }
            protected function getJsonPayload() {
                $address = ['address1' => 'Via Roma 1', 'city' => 'Milano', 'postcode' => '20100', 'country' => 'IT'];
                return ['id_cart' => 42, 'id_guest' => 8, 'id_carrier' => 20, 'delivery_address' => $address,
                    'invoice_address' => $address, 'firstname' => 'Ada', 'lastname' => 'Rossi'];
            }
            protected function getOwnedCart($cart, $customer, $guest) { return new Cart(); }
            public function quote() { return $this->handleRequest(); }
        };
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertSame(['shipping_cents' => 780], $controller->quote());
        $pdo = Db::getInstance()->pdo;
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
        self::assertSame(19, (int) $pdo->query('SELECT carrier FROM carts WHERE id = 42')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
        Cart::$failQuote = true;
        try {
            $controller->quote();
            self::fail('The quote failure should propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('quote failed', $error->getMessage());
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM addresses')->fetchColumn());
        self::assertSame(19, (int) $pdo->query('SELECT carrier FROM carts WHERE id = 42')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
        Hook::$registered = false;
        $this->expectException(MlabFactoryApiException::class);
        $controller->quote();
    }
}
