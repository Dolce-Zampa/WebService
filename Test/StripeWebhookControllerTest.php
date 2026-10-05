<?php
declare(strict_types=1);

use PS\Webservice\Http\Controller\StripeWebhookController;
use PS\Webservice\Repositories\OrderRepository;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class StripeWebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $cache = $this->getMockBuilder(\Illuminate\Cache\Repository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['has', 'get', 'put', 'forget', 'forever', 'tags'])
            ->getMock();

        $taggedCache = $this->getMockBuilder(\Illuminate\Cache\TaggedCache::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['has', 'get', 'tags'])
            ->getMock();

        $cache->method('tags')
            ->with($this->anything())
            ->willReturn($taggedCache);

        $taggedCache->method('tags')
            ->with($this->anything())
            ->willReturn($taggedCache);

        \Illuminate\Support\Facades\Facade::setFacadeApplication([
            'cache' => $cache,
            'log' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'queue-service' => $this->createMock(\PS\Webservice\Service\RedisQueue::class)  
        ]);

        $this->cache = $cache;
        $this->taggedCache = $taggedCache;
    }

    private function buildRequest(string $body, string $sigHeader = ''): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')
            ->with('Stripe-Signature')
            ->willReturn($sigHeader);

        return $request;
    }

    private function buildController(?Order $orderService = null): StripeWebhookController
    {
        return new StripeWebhookController(
            $orderService ?? $this->createMock(Order::class),
            $this->createMock(MailjetService::class),
            $this->createMock(PaymentGatewayInterface::class),
            $this->createMock(MailerInterface::class),
            $this->createMock(OrderRepository::class)
        );
    }

    /** Build a controller with a stubbed Stripe event so constructEvent is bypassed. */
    private function buildControllerWithEvent(\Stripe\Event $event, ?Order $orderService = null): StripeWebhookController
    {
        $service = $orderService ?? $this->createMock(Order::class);
        $mailjet = $this->createMock(MailjetService::class);
        $stripe = $this->createMock(PaymentGatewayInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $orderRepo = $this->createMock(OrderRepository::class);

        return new class($service, $mailjet, $stripe, $mailer, $orderRepo, $event) extends StripeWebhookController {
            private \Stripe\Event $stubbedEvent;

            public function __construct(
                Order $orderService,
                MailjetService $mailjetService,
                PaymentGatewayInterface $stripeService,
                MailerInterface $mailer,
                OrderRepository $orderRepository,
                \Stripe\Event $event
            ) {
                parent::__construct($orderService, $mailjetService, $stripeService, $mailer, $orderRepository);
                $this->stubbedEvent = $event;
            }

            protected function getFromCache(string $key): mixed
            {
                return ['orderSession' => [
                    'id_customer' => 7,
                    'id_guest' => null,
                    'customer' => [
                        'email' => 'john.doe@example.com',
                        'firstname' => 'Mario',
                        'lastname' => 'Rossi',
                        'phone' => '3319843630',
                        'delivery_address' => ['address1' => 'Via Roma 1', 'city' => 'Roma', 'postcode' => '00100'],
                    ],
                ]];
            }

            protected function constructStripeEvent(string $payload, string $sigHeader, string $secret): \Stripe\Event
            {
                return $this->stubbedEvent;
            }
        };
    }

    // ---------------------------------------------------------------------------
    // handleWebhook error paths
    // ---------------------------------------------------------------------------

    public function test_returns_500_when_webhook_secret_not_configured(): void
    {
        unset($_ENV['STRIPE_WEBHOOK_SECRET']);

        $controller = $this->buildController();
        $request = $this->buildRequest('{}', 'some-sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
    }

    public function test_returns_400_on_invalid_relative_signature(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $controller = $this->buildController();
        $request = $this->buildRequest('{}', 'invalid-signature');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
    }

    public function test_returns_400_on_invalid_payload(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $controller = $this->buildController();
        // Empty payload with empty signature triggers UnexpectedValueException
        $request = $this->buildRequest('', '');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        // Stripe throws UnexpectedValueException for empty/malformed payload
        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
    }

    public function test_returns_200_for_unhandled_event_types(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $event = \Stripe\Event::constructFrom(['id' => 'evt_1', 'type' => 'payment_intent.created', 'data' => ['object' => []]]);
        $controller = $this->buildControllerWithEvent($event);
        $request = $this->buildRequest('{}', 'sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['data']['received']);
    }

    public function test_handle_checkout_session_expired_accepts_array_from_cache(): void
    {
        $orderService = $this->createMock(Order::class);
        $paymentService = $this->createMock(PaymentGatewayInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('sendRecoveryCartExpired');

        $cachedSession = [
            'orderSession' => [
                'cart_id' => 456,
                'id_customer' => null,
                'id_guest' => 196,
                'id_carrier' => 15,
                'customer' => [
                    'email' => 'john.doe@example.com',
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'phone' => '3319843630',
                    'newsletter' => false,
                    'delivery_address' => null,
                    'invoice_address' => null,
                ],
            ],
            'cart' => ['products' => []],
        ];

        $controller = new class($orderService, $this->createMock(MailjetService::class), $paymentService, $mailer, $cachedSession) extends StripeWebhookController {
            protected array $tags = [];
            private array $cachedSession;

            public function __construct(Order $orderService, MailjetService $mailjetService, PaymentGatewayInterface $stripeService, MailerInterface $mailer, array $cachedSession)
            {
                parent::__construct($orderService, $mailjetService, $stripeService, $mailer);
                $this->cachedSession = $cachedSession;
            }

            public function tags(array $tags): self
            {
                $this->tags = $tags;
                return $this;
            }

            protected function getFromCache(string $key): mixed
            {
                return $this->cachedSession;
            }

            protected function setToCache(mixed $key, mixed $value, ?int $ttl = null): void
            {
                // Intentionally no-op for the regression test.
            }
        };

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_expired_array_cache',
            'metadata' => [
                'cart_id' => '456',
                'id_carrier' => '15',
                'id_guest' => '196',
                'recovery_attempt' => true,
                'customer_email' => 'john.doe@example.com',
            ],
        ]);

        $controller->handleCheckoutSessionExpired($session);
        $this->addToAssertionCount(1);
    }

    public function test_handle_checkout_session_expired_returns_200_when_cache_is_missing(): void
    {
        $orderService = $this->createMock(Order::class);
        $paymentService = $this->createMock(PaymentGatewayInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('sendRecoveryCartExpired');

        $controller = new class($orderService, $this->createMock(MailjetService::class), $paymentService, $mailer) extends StripeWebhookController {
            protected array $tags = [];

            public function tags(array $tags): self
            {
                $this->tags = $tags;
                return $this;
            }

            protected function getFromCache(string $key): mixed
            {
                return null;
            }
        };

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_expired_missing_cache',
            'metadata' => [
                'cart_id' => '456',
                'id_carrier' => '15',
                'id_guest' => '196',
                'recovery_attempt' => false,
                'customer_email' => 'john.doe@example.com',
            ],
        ]);

        $controller->handleCheckoutSessionExpired($session);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------
    // handleCheckoutSessionCompleted – happy path
    // ---------------------------------------------------------------------------

    public function test_confirms_order_on_checkout_session_completed(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';
        putenv('MAILJET_CLIENTI_LIST_ID');
        unset($_ENV['MAILJET_CLIENTI_LIST_ID'], $_SERVER['MAILJET_CLIENTI_LIST_ID']);

        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->once())->method('confirmSessionOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_1',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => [
                'cart_id' => '42',
                'id_customer' => '7',
                'id_carrier' => '3',
                'customer_email' => 'john.doe@example.com',
                'customer' => json_encode(['email' => 'john.doe@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi']),
            ],
        ]);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session->toArray()],
        ]);

        $controller = $this->buildControllerWithEvent($event, $orderService);
        $request = $this->buildRequest('{}', 'sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(200, $result->getStatusCode());
    }

    // ---------------------------------------------------------------------------
    // handleCheckoutSessionCompleted – validation failures
    // ---------------------------------------------------------------------------

    public function test_returns_200_when_cart_id_missing_from_metadata(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_2',
            'amount_total' => 5000,
            'metadata' => [
                'customer_email' => 'john.doe@example.com',
                'customer' => json_encode(['email' => 'john.doe@example.com', 'firstname' => 'X', 'lastname' => 'Y']),
            ],
            'customer_details' => ['email' => 'john.doe@example.com', 'name' => 'X', ],
        ]);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_2',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session->toArray()],
        ]);

        $controller = $this->buildControllerWithEvent($event, $orderService);
        $request = $this->buildRequest('{}', 'sig');

        // Missing cart_id – handler returns early; overall response is 200 (no retry needed)
        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_checkout_without_carrier_metadata_is_rejected(): void
    {
        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');
        $controller = $this->controllerWithCheckoutCache($orderService, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing id_carrier in Stripe session metadata');
        $controller->handleCheckoutSessionCompleted(\Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_missing_carrier',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '42'],
        ]));
    }

    public function test_returns_500_when_amount_total_is_zero(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_4',
            'amount_total' => 0,
            'currency' => 'eur',
            'metadata' => [
                'cart_id' => '42',
                'id_carrier' => '3',
                'customer_email' => 'john.doe@example.com',
                'customer' => json_encode(['email' => 'john.doe@example.com', 'firstname' => 'X', 'lastname' => 'Y']),
            ],
        ]);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_4',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session->toArray()],
        ]);

        $controller = $this->buildControllerWithEvent($event, $orderService);
        $request = $this->buildRequest('{}', 'sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
    }

    public function test_returns_500_when_currency_is_not_eur(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_5',
            'amount_total' => 5000,
            'currency' => 'jpy',
            'metadata' => [
                'cart_id' => '42',
                'id_carrier' => '3',
                'customer' => json_encode(['email' => 'john.doe@example.com', 'firstname' => 'X', 'lastname' => 'Y']),
            ],
        ]);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_5',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session->toArray()],
        ]);

        $controller = $this->buildControllerWithEvent($event, $orderService);
        $request = $this->buildRequest('{}', 'sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
    }

    public function test_guest_checkout_confirms_without_a_registered_customer(): void
    {
        $customer = [
            'email' => 'guest@example.com', 'firstname' => 'Stefano', 'lastname' => 'Galmarini',
            'phone' => '3312345678', 'newsletter' => false,
            'delivery_address' => ['address1' => 'Via Consegna 1', 'city' => 'Arcisate', 'postcode' => '21051'],
        ];
        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->once())->method('confirmSessionOrder')->with(
            621, null, 359, 15, null, 'guest@example.com', 'Stefano', 'Galmarini',
            $this->callback(function ($details) use ($customer): bool {
                return $details->delivery_address['address1'] === $customer['delivery_address']['address1']
                    && $details->delivery_address['country'] === 'IT'
                    && $details->phone === '3312345678'
                    && $details->payment_module === 'webserviceapi'
                    && $details->create_account === true
                    && $details->newsletter === true;
            }), 104.0
        );
        $controller = $this->controllerWithCheckoutCache($orderService, ['orderSession' => [
            'id_customer' => null, 'id_guest' => 359, 'customer' => $customer,
        ]]);
        $controller->handleCheckoutSessionCompleted(\Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_guest', 'amount_total' => 10400, 'currency' => 'eur',
            'metadata' => [
                'cart_id' => '621',
                'id_guest' => '359',
                'id_carrier' => '15',
                'customer_email' => 'guest@example.com',
                'payment_module' => 'webserviceapi',
                'create_account' => 'true',
                'newsletter' => 'true',
            ],
            'customer_details' => ['address' => ['line1' => 'Different billing address']],
            'shipping_details' => ['address' => [
                'line1' => 'Via Consegna 1',
                'city' => 'Arcisate',
                'postal_code' => '21051',
                'country' => 'IT',
            ]],
        ]));
    }

    public function test_invalid_stripe_shipping_address_is_rejected(): void
    {
        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');
        $controller = $this->controllerWithCheckoutCache($orderService, ['orderSession' => [
            'id_customer' => null,
            'id_guest' => 359,
            'customer' => [
                'email' => 'guest@example.com',
                'firstname' => 'Stefano',
                'lastname' => 'Galmarini',
                'delivery_address' => ['address1' => 'Via Consegna 1', 'city' => 'Arcisate', 'postcode' => '21051'],
            ],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incomplete Stripe delivery address data.');
        $controller->handleCheckoutSessionCompleted(\Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_invalid_address',
            'amount_total' => 10400,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '621', 'id_guest' => '359', 'id_carrier' => '15'],
            'shipping_details' => ['address' => ['line1' => 'Via Consegna 1']],
        ]));
    }

    public function test_guest_checkout_accepts_the_cached_order_session_object(): void
    {
        $orderService = $this->createMock(Order::class);
        $customer = \PS\Webservice\Domain\Entities\CustomerEntity::create([
            'email' => 'guest@example.com', 'firstname' => 'Stefano', 'lastname' => 'Galmarini',
            'phone' => '3312345678', 'newsletter' => false,
            'delivery_address' => ['address1' => 'Via Consegna 1', 'city' => 'Arcisate', 'postcode' => '21051'],
        ], $orderService);
        $cached = \PS\Webservice\Domain\Object\OrderSession::create([
            'cart_id' => 621, 'id_customer' => null, 'id_guest' => 359,
            'id_carrier' => 15, 'customer' => $customer,
        ], $orderService);
        $orderService->expects($this->exactly(2))->method('confirmSessionOrder')->with(
            621, null, 359, 15, null, 'guest@example.com', 'Stefano', 'Galmarini',
            $this->callback(fn ($details) => $details->delivery_address['address1'] === 'Via Consegna 1'), 104.0
        );
        foreach ([$cached, ['orderSession' => $cached, 'cart' => []]] as $cacheEntry) {
            $this->controllerWithCheckoutCache($orderService, $cacheEntry)
                ->handleCheckoutSessionCompleted($this->guestSession());
        }
    }

    public function test_missing_guest_checkout_cache_keeps_event_retryable(): void
    {
        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');
        $controller = $this->controllerWithCheckoutCache($orderService, null);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing checkout customer data for cart 621');
        $controller->handleCheckoutSessionCompleted($this->guestSession());
    }

    public function test_checkout_cache_for_another_guest_is_rejected(): void
    {
        $orderService = $this->createMock(Order::class);
        $orderService->expects($this->never())->method('confirmSessionOrder');
        $controller = $this->controllerWithCheckoutCache($orderService, [
            'id_guest' => 360, 'customer' => [],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Checkout customer identity mismatch');
        $controller->handleCheckoutSessionCompleted($this->guestSession());
    }

    private function guestSession(): \Stripe\Checkout\Session
    {
        return \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_guest', 'amount_total' => 10400, 'currency' => 'eur',
            'metadata' => ['cart_id' => '621', 'id_guest' => '359', 'id_carrier' => '15'],
        ]);
    }

    private function controllerWithCheckoutCache(Order $orderService, mixed $cached): StripeWebhookController
    {
        return new class($orderService, $this->createMock(MailjetService::class),
            $this->createMock(PaymentGatewayInterface::class), $this->createMock(MailerInterface::class), $cached
        ) extends StripeWebhookController {
            public function __construct(Order $order, MailjetService $mailjet, PaymentGatewayInterface $stripe, MailerInterface $mailer, private mixed $cached)
            {
                parent::__construct($order, $mailjet, $stripe, $mailer);
            }

            protected function getFromCache(string $key): mixed
            {
                return $this->cached;
            }
        };
    }

}
