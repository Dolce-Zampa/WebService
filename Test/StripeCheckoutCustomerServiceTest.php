<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\Payments\PaymentService;
use PS\Webservice\Service\Payments\StripeCheckoutCustomerService;
use PS\Webservice\Service\PS\Order;

final class StripeCheckoutCustomerServiceTest extends TestCase
{
    public function test_checkout_uses_one_persisted_stripe_customer_across_retries(): void
    {
        $customers = new class {
            /** @var array<int, array{params:array<string,mixed>,options:array<string,mixed>}> */
            public array $calls = [];

            public function create(array $params, array $options): \Stripe\Customer
            {
                $this->calls[] = ['params' => $params, 'options' => $options];
                return \Stripe\Customer::constructFrom(['id' => 'cus_checkoutbuyer10']);
            }
        };
        $sessions = new class {
            /** @var array<int, array{params:array<string,mixed>,options:array<string,mixed>}> */
            public array $calls = [];

            public function create(array $params, array $options): \Stripe\Checkout\Session
            {
                $this->calls[] = ['params' => $params, 'options' => $options];
                return \Stripe\Checkout\Session::constructFrom(['id' => 'cs_' . count($this->calls), 'url' => 'https://checkout.example.test/' . count($this->calls)]);
            }
        };
        $checkout = new \stdClass();
        $checkout->sessions = $sessions;
        $stripe = new class($customers, $checkout) extends \Stripe\StripeClient {
            public function __construct(private object $customersService, private object $checkoutService)
            {
                parent::__construct('sk_test_123');
            }

            public function getService($name)
            {
                return match ($name) {
                    'customers' => $this->customersService,
                    'checkout' => $this->checkoutService,
                    default => parent::getService($name),
                };
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
        $session->setCheckoutAttempt('dz_checkout_v1_' . $session->checkoutAttemptFingerprint() . '_1', time() + 3600);

        $db = $this->database();
        $payment = new PaymentService($stripe, null, new StripeCheckoutCustomerService($db));

        $payment->createPaymentSession($session);
        $payment->createPaymentSession($session);

        self::assertCount(1, $customers->calls);
        self::assertSame('buyer@example.test', $customers->calls[0]['params']['email']);
        self::assertSame('Buyer Example', $customers->calls[0]['params']['name']);
        self::assertSame('prestashop_customer:10', $customers->calls[0]['params']['metadata']['prestashop_customer_key']);
        self::assertSame('10', $customers->calls[0]['params']['metadata']['prestashop_customer_id']);
        self::assertStringStartsWith('dz_stripe_customer_v1_', $customers->calls[0]['options']['idempotency_key']);
        self::assertCount(2, $sessions->calls);
        self::assertSame('cus_checkoutbuyer10', $sessions->calls[0]['params']['customer']);
        self::assertSame('cus_checkoutbuyer10', $sessions->calls[1]['params']['customer']);
        self::assertSame(
            'cus_checkoutbuyer10',
            $db->table('stripe_checkout_customers')->where('customer_key', 'prestashop_customer:10')->value('stripe_customer_id'),
        );
    }

    private function database(): Manager
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        $db->schema()->create('stripe_checkout_customers', function ($table): void {
            $table->increments('id');
            $table->string('customer_key', 64)->unique();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        return $db;
    }
}
