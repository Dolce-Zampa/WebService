<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\Payments\PaymentService;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CheckoutAttemptCacheHarness
{
    use UseCache;

    /** @param array<string, mixed> $record */
    public function cacheExpiredAttempt(string $fingerprint, array $record): void
    {
        $this->tags(['stripe-checkout-attempt'])->setEncryptedToCache($fingerprint, $record, 1560);
    }
}

final class CheckoutIdempotencyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cache = [];

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->createMock(\Illuminate\Cache\Repository::class);
        $taggedCache = $this->createMock(\Illuminate\Cache\TaggedCache::class);
        $repository->method('tags')->willReturn($taggedCache);
        $taggedCache->method('tags')->willReturn($taggedCache);
        $taggedCache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cache[$key] ?? null);
        $taggedCache->method('put')->willReturnCallback(function (string $key, mixed $value): bool {
            $this->cache[$key] = $value;
            return true;
        });

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication([
            'cache' => $repository,
            'log' => $this->createMock(\Psr\Log\LoggerInterface::class),
        ]);
    }

    public function test_reuses_an_open_session_and_replaces_an_expired_attempt(): void
    {
        $orderService = $this->orderService();
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $sessions = [];
        $gateway->expects(self::exactly(2))->method('createPaymentSession')
            ->willReturnCallback(function (OrderSession $session) use (&$sessions): string {
                $sessions[] = $session;
                return 'https://checkout.example.test/session-' . count($sessions);
            });
        $controller = new OrderController($orderService, $gateway);

        $first = $controller->createOrder($this->request(), $this->createMock(ResponseInterface::class), []);
        $retry = $controller->createOrder($this->request(), $this->createMock(ResponseInterface::class), []);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(201, $retry->getStatusCode());
        self::assertSame(
            'https://checkout.example.test/session-1',
            json_decode((string) $retry->getBody(), true)['data']['payment_url']
        );
        self::assertCount(1, $sessions);

        $firstPayload = $sessions[0]->toArray();
        self::assertMatchesRegularExpression(
            '/^dz_checkout_v1_[a-f0-9]{64}_[1-9][0-9]*$/',
            $firstPayload['metadata']['checkout_attempt_key']
        );

        preg_match('/_([0-9]+)$/', $firstPayload['metadata']['checkout_attempt_key'], $matches);
        (new CheckoutAttemptCacheHarness())->cacheExpiredAttempt(
            $sessions[0]->checkoutAttemptFingerprint(),
            [
                'state' => 'open',
                'version' => (int) $matches[1],
                'expires_at' => time() - 1,
                'url' => 'https://checkout.example.test/session-1',
            ]
        );

        $replacement = $controller->createOrder($this->request(), $this->createMock(ResponseInterface::class), []);
        self::assertSame(
            'https://checkout.example.test/session-2',
            json_decode((string) $replacement->getBody(), true)['data']['payment_url']
        );
        self::assertCount(2, $sessions);
        self::assertNotSame(
            $sessions[0]->metadata['checkout_attempt_key'],
            $sessions[1]->metadata['checkout_attempt_key']
        );
    }

    public function test_payment_service_passes_the_checkout_attempt_as_stripe_idempotency_key(): void
    {
        $sessions = new class {
            /** @var array<string, mixed> */
            public array $params = [];
            /** @var array<string, mixed> */
            public array $options = [];

            public function create(array $params, array $options): \Stripe\Checkout\Session
            {
                $this->params = $params;
                $this->options = $options;
                return \Stripe\Checkout\Session::constructFrom(['url' => 'https://checkout.example.test/idempotent']);
            }
        };
        $checkout = new \stdClass();
        $checkout->sessions = $sessions;
        $stripe = new class($checkout) extends \Stripe\StripeClient {
            public function __construct(private object $checkout)
            {
                parent::__construct('sk_test_123');
            }

            public function getService($name)
            {
                return $name === 'checkout' ? $this->checkout : parent::getService($name);
            }
        };
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42,
            'id_customer' => 10,
            'id_carrier' => 2,
            'customer' => CustomerEntity::create([
                'email' => 'buyer@example.test',
                'firstname' => 'Buyer',
                'lastname' => 'Example',
            ], $order),
        ], $order);
        $fingerprint = $session->checkoutAttemptFingerprint();
        $session->setCheckoutAttempt('dz_checkout_v1_' . $fingerprint . '_1', time() + 3600);

        $url = (new PaymentService($stripe))->createPaymentSession($session);

        self::assertSame('https://checkout.example.test/idempotent', $url);
        self::assertSame($session->metadata['checkout_attempt_key'], $sessions->options['idempotency_key']);
        self::assertSame($session->metadata['checkout_attempt_key'], $sessions->params['metadata']['checkout_attempt_key']);
    }

    private function orderService(): Order
    {
        $service = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId', 'prepareCouponCart'])
            ->getMock();
        $cart = CartEntity::create([
            'id' => 10,
            'id_carrier' => 2,
            'products' => [[
                'id_product' => 7,
                'id_product_attribute' => 0,
                'name' => 'Product',
                'reference' => 'SKU-7',
                'quantity' => 1,
                'price_wt' => 10.0,
            ]],
            'totals' => ['shipping_tax_incl' => 2.0],
            'cart_rules' => [],
        ], $service);
        $service->method('getCartFromId')->willReturn($cart);
        $service->method('prepareCouponCart')->willReturn($cart);

        return $service;
    }

    private function request(): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id' => 1,
            'id_cart' => 10,
            'id_customer' => 5,
            'id_guest' => null,
            'id_carrier' => 2,
            'id_lang' => 1,
            'reference' => 'REF-10',
            'current_state' => 0,
            'date_add' => '2026-10-22 10:00:00',
            'total_paid_tax_incl' => 12.0,
            'total_paid_tax_excl' => 10.0,
            'customer' => [
                'id_customer' => 5,
                'email' => 'buyer@example.test',
                'firstname' => 'Buyer',
                'lastname' => 'Example',
            ],
            'delivery_address' => ['address1' => 'Via Example 1', 'city' => 'Roma', 'postcode' => '00100', 'country' => 'IT', 'id_country' => 10],
            'invoice_address' => ['address1' => 'Via Example 1', 'city' => 'Roma', 'postcode' => '00100', 'country' => 'IT', 'id_country' => 10],
            'cart_rules' => [],
            'payment_module' => 'webserviceapi',
        ]);

        return $request;
    }
}
