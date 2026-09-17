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
            'log' => $this->createMock(\Psr\Log\LoggerInterface::class)
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
}
