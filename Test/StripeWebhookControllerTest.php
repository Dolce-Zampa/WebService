<?php
declare(strict_types=1);

use DolzeZampa\WS\Domain\Entities\OrderEntity;
use DolzeZampa\WS\Http\Controller\StripeWebhookController;
use DolzeZampa\WS\Service\PS\Order;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class StripeWebhookControllerTest extends TestCase
{
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

    /** Build a controller with a stubbed Stripe event so constructEvent is bypassed. */
    private function buildControllerWithEvent(\Stripe\Event $event, ?Order $orderService = null): StripeWebhookController
    {
        $service = $orderService ?? $this->createMock(Order::class);

        return new class($service, $event) extends StripeWebhookController {
            private \Stripe\Event $stubbedEvent;

            public function __construct(Order $orderService, \Stripe\Event $event)
            {
                parent::__construct($orderService);
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

        $controller = new StripeWebhookController($this->createMock(Order::class));
        $request = $this->buildRequest('{}', 'some-sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_returns_400_on_invalid_stripe_signature(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $controller = new StripeWebhookController($this->createMock(Order::class));
        $request = $this->buildRequest('{}', 'invalid-signature');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_returns_400_on_invalid_payload(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $controller = new StripeWebhookController($this->createMock(Order::class));
        // Empty payload with empty signature triggers UnexpectedValueException
        $request = $this->buildRequest('', '');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        // Stripe throws UnexpectedValueException for empty/malformed payload
        $this->assertSame(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
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
        $this->assertTrue($body['received']);
    }

    // ---------------------------------------------------------------------------
    // handleCheckoutSessionCompleted – happy path
    // ---------------------------------------------------------------------------

    public function test_confirms_order_on_checkout_session_completed(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->method('getOrderByCartId')->with(42)->willReturn(null);
        $orderService->expects($this->once())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_1',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '42', 'id_customer' => '7', 'id_carrier' => '3'],
            'customer_details' => ['email' => 'mario@example.com', 'name' => 'Mario Rossi'],
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
    // handleCheckoutSessionCompleted – idempotency
    // ---------------------------------------------------------------------------

    public function test_skips_confirmation_when_order_already_exists(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $existingOrder = $this->createMock(OrderEntity::class);
        $orderService->method('getOrderByCartId')->with(42)->willReturn($existingOrder);
        $orderService->expects($this->never())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_1',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '42', 'id_customer' => '7', 'id_carrier' => '3'],
            'customer_details' => ['email' => 'mario@example.com', 'name' => 'Mario Rossi'],
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
        $orderService->expects($this->never())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_2',
            'amount_total' => 5000,
            'metadata' => [],
            'customer_details' => ['email' => 'x@example.com', 'name' => 'X'],
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

    public function test_returns_500_when_id_carrier_missing_from_metadata(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->method('getOrderByCartId')->willReturn(null);
        $orderService->expects($this->never())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_3',
            'amount_total' => 5000,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '42'],
            'customer_details' => ['email' => 'x@example.com', 'name' => 'X'],
        ]);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_3',
            'type' => 'checkout.session.completed',
            'data' => ['object' => $session->toArray()],
        ]);

        $controller = $this->buildControllerWithEvent($event, $orderService);
        $request = $this->buildRequest('{}', 'sig');

        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_returns_500_when_amount_total_is_zero(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->method('getOrderByCartId')->willReturn(null);
        $orderService->expects($this->never())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_4',
            'amount_total' => 0,
            'currency' => 'eur',
            'metadata' => ['cart_id' => '42', 'id_carrier' => '3'],
            'customer_details' => ['email' => 'x@example.com', 'name' => 'X'],
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
        $this->assertArrayHasKey('error', $body);
    }

    public function test_returns_500_when_currency_is_not_eur(): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test123';

        $orderService = $this->createMock(Order::class);
        $orderService->method('getOrderByCartId')->willReturn(null);
        $orderService->expects($this->never())->method('confirmOrder');

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_5',
            'amount_total' => 5000,
            'currency' => 'jpy',
            'metadata' => ['cart_id' => '42', 'id_carrier' => '3'],
            'customer_details' => ['email' => 'x@example.com', 'name' => 'X'],
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
        $this->assertArrayHasKey('error', $body);
    }
}
