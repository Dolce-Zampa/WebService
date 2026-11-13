<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PickupPoints\InpostCheckout;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InpostCheckoutFlowTest extends TestCase
{
    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_guest_checkout_persists_the_verified_point_for_the_order(): void
    {
        $_SERVER['APP_DISABLE_CACHE'] = $_ENV['APP_DISABLE_CACHE'] = 'false';
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_KEY'] = $_ENV['APP_KEY'] = 'inpost-checkout-testing';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => new Repository(new ArrayStore()), 'log' => new \Psr\Log\NullLogger()]);
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('inpost_sync', function ($t): void { $t->integer('id')->primary(); $t->dateTime('last_success_at'); });
        $db->schema()->create('inpost_points', function ($t): void {
            $t->string('point_id')->primary(); $t->string('address'); $t->string('city'); $t->string('postal_code'); $t->string('province');
        });
        $db->schema()->create('manufacturer_details', function ($t): void { $t->integer('id_manufacturer')->primary(); $t->string('commission'); });
        $db->schema()->create('stripe_checkout_snapshots', function ($t): void {
            $t->string('id')->primary(); $t->integer('cart_id'); $t->string('checkout_session_id')->nullable();
            $t->string('stripe_payment_intent_id')->nullable(); $t->integer('order_id')->nullable(); $t->string('transfer_group')->nullable();
            $t->string('currency'); $t->text('payload_ciphertext'); $t->string('payload_hash'); $t->text('verification_payload');
            $t->dateTime('created_at'); $t->dateTime('updated_at');
        });
        $db->table('inpost_sync')->insert(['id' => 1, 'last_success_at' => gmdate('Y-m-d H:i:s')]);
        $db->table('inpost_points')->insert(['point_id' => 'MI001', 'address' => 'Via Locker 1', 'city' => 'Milano', 'postal_code' => '20100', 'province' => 'MI']);
        $orders = $this->createMock(Order::class);
        $product = ['id_product' => 7, 'id_manufacturer' => 1, 'name' => 'Product', 'quantity' => 1, 'price_wt' => 10.0];
        $cart = CartEntity::create(['id' => 10, 'id_carrier' => 17, 'products' => [$product],
            'totals' => ['shipping_tax_incl' => 2.0], 'cart_rules' => []], $orders);
        $orders->method('getCartFromId')->with(10, null, 8)->willReturn($cart);
        $orders->expects(self::once())->method('prepareCouponCart')->with(10, null, 8, 17, [], self::callback(function ($checkout): bool {
            return $checkout['delivery_address']['address1'] === 'Via Locker 1'
                && $checkout['delivery_address']['address2'] === 'InPost MI001'
                && $checkout['invoice_address']['address1'] === 'Via Casa 1';
        }))->willReturn($cart);
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())->method('createPaymentSession')->willReturnCallback(function (OrderSession $session): string {
            self::assertSame('MI001', $session->metadata['inpost_point_id']);
            self::assertSame('Via Locker 1', $session->getCustomer()->delivery_address['address1']);
            return 'https://checkout.example.test/session';
        });
        $controller = new OrderController($orders, $gateway, null, new CheckoutSnapshotService($db), null, null, null,
            new InpostCheckout($db->getConnection(), [17]));
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id' => 1, 'id_cart' => 10, 'id_customer' => null, 'id_guest' => 8,
            'id_carrier' => 17, 'inpost_point_id' => 'MI001', 'id_lang' => 1, 'reference' => '', 'current_state' => 0,
            'date_add' => '', 'total_paid_tax_incl' => 0, 'total_paid_tax_excl' => 0, 'payment_module' => 'webserviceapi',
            'customer' => ['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'],
            'delivery_address' => ['address1' => 'Forged address', 'city' => 'Milano', 'postcode' => '20100', 'country' => 'IT'],
            'invoice_address' => ['address1' => 'Via Casa 1', 'city' => 'Milano', 'postcode' => '20100', 'country' => 'IT'], 'cart_rules' => []]);
        $result = $controller->createOrder($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(201, $result->getStatusCode(), (string) $result->getBody());
        self::assertSame(1, $db->table('stripe_checkout_snapshots')->count());
    }
}
