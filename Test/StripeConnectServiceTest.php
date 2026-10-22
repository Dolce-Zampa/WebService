<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Payments\StripeConnectService;

final class StripeConnectServiceTest extends TestCase
{
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => new \Psr\Log\NullLogger()]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }

    public function test_creates_one_express_account_per_manufacturer(): void
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        $db->schema()->create('stripe_connect_accounts', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id')->unique();
            $table->string('stripe_account_id')->unique();
            $table->string('account_type');
            $table->string('country', 2);
            $table->boolean('details_submitted');
            $table->boolean('charges_enabled');
            $table->boolean('payouts_enabled');
            $table->boolean('transfers_enabled');
            $table->text('requirements_due')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        $accounts = new class {
            public int $createCalls = 0;
            public int $loginLinkCalls = 0;
            public array $createParams = [];
            public array $createOptions = [];
            public ?string $loginLinkAccountId = null;

            public function create(array $params, array $options): \Stripe\Account
            {
                ++$this->createCalls;
                $this->createParams = $params;
                $this->createOptions = $options;
                return $this->account();
            }

            public function retrieve(string $id, array $params): \Stripe\Account
            {
                return $this->account();
            }

            public function createLoginLink(string $accountId, array $params): \Stripe\LoginLink
            {
                ++$this->loginLinkCalls;
                $this->loginLinkAccountId = $accountId;
                return \Stripe\LoginLink::constructFrom([
                    'url' => 'https://connect.stripe.com/express/acct_manufacturer_7/login-token',
                ]);
            }

            private function account(): \Stripe\Account
            {
                return \Stripe\Account::constructFrom([
                    'id' => 'acct_manufacturer_7',
                    'type' => 'express',
                    'country' => 'IT',
                    'details_submitted' => false,
                    'charges_enabled' => false,
                    'payouts_enabled' => false,
                    'capabilities' => ['transfers' => 'inactive'],
                    'requirements' => ['currently_due' => ['external_account']],
                ]);
            }
        };
        $stripe = new class($accounts) extends \Stripe\StripeClient {
            public function __construct(private object $accounts)
            {
                parent::__construct('sk_test_123');
            }

            public function getService($name)
            {
                return $name === 'accounts' ? $this->accounts : parent::getService($name);
            }
        };

        $service = new StripeConnectService($db, null, $stripe);
        $first = $service->createAccount(7, 'seller@example.test');
        $second = $service->createAccount(7, 'seller@example.test');

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(1, $accounts->createCalls);
        self::assertSame('express', $accounts->createParams['type']);
        self::assertSame([
            'card_payments' => ['requested' => true],
            'transfers' => ['requested' => true],
        ], $accounts->createParams['capabilities']);
        self::assertSame('7', $accounts->createParams['metadata']['manufacturer_id']);
        self::assertSame('connect:account:v2:manufacturer:7', $accounts->createOptions['idempotency_key']);

        self::assertSame([
            'dashboard_url' => 'https://connect.stripe.com/express/acct_manufacturer_7/login-token',
        ], $service->createDashboardLoginLink(7));
        self::assertSame(1, $accounts->loginLinkCalls);
        self::assertSame('acct_manufacturer_7', $accounts->loginLinkAccountId);
    }

    public function test_keeps_an_unregistered_seller_payout_pending_then_transfers_it_when_connect_becomes_ready(): void
    {
        $previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $previousEnabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? null;
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'connect';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';

        try {
            $db = new Manager();
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $this->createTransferTables($db);
            $db->table('manufacturer_details')->insert(['id_manufacturer' => 7, 'commission' => '20.00']);
            $db->table('product')->insert(['id_product' => 10, 'id_manufacturer' => 7]);
            // The product line may be changed by PrestaShop's internal cart
            // reconciliation rule. Payout must instead start from what the
            // buyer actually paid (€66.10), less shipping (€6.10).
            $db->table('orders')->insert(['id_order' => 42, 'total_shipping_tax_incl' => '6.10']);
            $db->table('order_detail')->insert(['id_order' => 42, 'product_id' => 10, 'total_price_tax_incl' => '45.00']);

            $accounts = new class {
                public bool $ready = false;

                public function create(array $params, array $options): \Stripe\Account
                {
                    return $this->account();
                }

                public function retrieve(string $id, array $params): \Stripe\Account
                {
                    return $this->account();
                }

                public function account(): \Stripe\Account
                {
                    return \Stripe\Account::constructFrom([
                        'id' => 'acct_manufacturer_7',
                        'type' => 'express',
                        'country' => 'IT',
                        'details_submitted' => $this->ready,
                        'charges_enabled' => $this->ready,
                        'payouts_enabled' => $this->ready,
                        'capabilities' => ['transfers' => $this->ready ? 'active' : 'inactive'],
                        'requirements' => ['currently_due' => $this->ready ? [] : ['external_account']],
                    ]);
                }
            };
            $paymentIntents = new class {
                public function retrieve(string $id, array $params): \Stripe\PaymentIntent
                {
                    return \Stripe\PaymentIntent::constructFrom([
                        'id' => $id,
                        'currency' => 'eur',
                        'amount_received' => 6610,
                        'latest_charge' => 'ch_order_42',
                    ]);
                }
            };
            $transfers = new class {
                public int $createCalls = 0;
                /** @var array<string, mixed> */
                public array $lastParams = [];

                public function create(array $params, array $options): \Stripe\Transfer
                {
                    ++$this->createCalls;
                    $this->lastParams = $params;
                    return \Stripe\Transfer::constructFrom(['id' => 'tr_pending_42']);
                }
            };
            $stripe = new class($accounts, $paymentIntents, $transfers) extends \Stripe\StripeClient {
                public function __construct(private object $accounts, private object $paymentIntents, private object $transfers)
                {
                    parent::__construct('sk_test_123');
                }

                public function getService($name)
                {
                    return match ($name) {
                        'accounts' => $this->accounts,
                        'paymentIntents' => $this->paymentIntents,
                        'transfers' => $this->transfers,
                        default => parent::getService($name),
                    };
                }
            };

            $service = new StripeConnectService($db, null, $stripe);
            $service->createTransfersForOrder(42, 'pi_order42', 'dz_cart_42', new DateTimeImmutable('2026-10-19 10:00:00 UTC'));

            $pending = $db->table('stripe_connect_transfers')->first();
            self::assertSame('pending', $pending->status);
            self::assertNull($pending->stripe_account_id);
            self::assertSame('seller_account_not_ready', $pending->failure_code);
            self::assertSame(4800, $pending->amount);
            self::assertSame(0, $transfers->createCalls);

            $service->createAccount(7, 'seller@example.test');
            $accounts->ready = true;
            self::assertSame(1, $service->handleAccountUpdated($accounts->account(), new DateTimeImmutable('2026-10-19 10:05:00 UTC')));

            $settled = $db->table('stripe_connect_transfers')->first();
            self::assertSame('paid', $settled->status);
            self::assertSame('acct_manufacturer_7', $settled->stripe_account_id);
            self::assertSame('tr_pending_42', $settled->stripe_transfer_id);
            self::assertNull($settled->failure_code);
            self::assertSame(1, $transfers->createCalls);
            self::assertSame(4800, $transfers->lastParams['amount']);

            self::assertSame(0, $service->handleAccountUpdated($accounts->account(), new DateTimeImmutable('2026-10-19 10:06:00 UTC')));
            self::assertSame(1, $transfers->createCalls);
        } finally {
            $this->restoreEnvironment('STRIPE_PAYMENT_FLOW', $previousFlow);
            $this->restoreEnvironment('STRIPE_CONNECT_ENABLED', $previousEnabled);
        }
    }

    public function test_prorates_successful_customer_refunds_and_reverses_only_paid_seller_transfers(): void
    {
        $previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $previousEnabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? null;
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'connect';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';

        try {
            $db = new Manager();
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $this->createTransferTables($db);
            $this->paidTransfer($db, 11, 7, 4800, 'tr_seller_7');
            $this->paidTransfer($db, 11, 8, 3200, 'tr_seller_8');

            $transfers = new class {
                /** @var list<array{transfer:string, amount:int, key:string}> */
                public array $reversals = [];

                public function createReversal(string $transferId, array $params, array $options): \Stripe\TransferReversal
                {
                    $this->reversals[] = [
                        'transfer' => $transferId,
                        'amount' => $params['amount'],
                        'key' => $options['idempotency_key'],
                    ];
                    return \Stripe\TransferReversal::constructFrom(['id' => 'trr_' . count($this->reversals)]);
                }
            };
            $service = new StripeConnectService($db, null, $this->recoveryStripeClient($transfers));
            $occurredAt = new DateTimeImmutable('2026-10-22 10:00:00 UTC');

            // A €50 refund on a €100 payment recovers 50% of each seller's
            // net transfer (€24 and €16); the remaining €10 is the platform
            // share and is not charged to either seller.
            $service->reconcileRefund('pi_recovery_11', 're_partial_11', 5000, 'EUR', 'available', $occurredAt);
            self::assertSame([
                ['transfer' => 'tr_seller_7', 'amount' => 2400, 'key' => $this->reversalKey('refund', 're_partial_11', 1)],
                ['transfer' => 'tr_seller_8', 'amount' => 1600, 'key' => $this->reversalKey('refund', 're_partial_11', 2)],
            ], $transfers->reversals);
            self::assertSame(['reversed', 'reversed'], $db->table('stripe_connect_transfer_reversals')->orderBy('id')->pluck('status')->all());

            // Stripe may retry the same event. The stored case id makes the
            // allocation and Transfer reversals exactly once.
            $service->reconcileRefund('pi_recovery_11', 're_partial_11', 5000, 'EUR', 'available', $occurredAt);
            self::assertCount(2, $transfers->reversals);

            // A second €50 refund reaches the original paid total, so only
            // the remaining seller amount is recovered.
            $service->reconcileRefund('pi_recovery_11', 're_remaining_11', 5000, 'EUR', 'available', $occurredAt);
            self::assertSame([2400, 1600, 2400, 1600], array_column($transfers->reversals, 'amount'));
        } finally {
            $this->restoreEnvironment('STRIPE_PAYMENT_FLOW', $previousFlow);
            $this->restoreEnvironment('STRIPE_CONNECT_ENABLED', $previousEnabled);
        }
    }

    public function test_cancels_unpaid_seller_transfer_and_records_dispute_for_manual_review(): void
    {
        $previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $previousEnabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? null;
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'connect';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';

        try {
            $db = new Manager();
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $this->createTransferTables($db);
            $this->pendingTransfer($db, 11, 7, 4800);
            $this->paidTransfer($db, 11, 8, 3200, 'tr_seller_8');

            $transfers = new class {
                public int $reverseCalls = 0;

                public function createReversal(string $transferId, array $params, array $options): \Stripe\TransferReversal
                {
                    ++$this->reverseCalls;
                    return \Stripe\TransferReversal::constructFrom(['id' => 'trr_unexpected']);
                }
            };
            $service = new StripeConnectService($db, null, $this->recoveryStripeClient($transfers));
            $service->reconcileRefund('pi_recovery_11', 're_full_11', 10000, 'EUR', 'available', new DateTimeImmutable('2026-10-22 10:00:00 UTC'));

            $pending = $db->table('stripe_connect_transfers')->where('manufacturer_id', 7)->first();
            self::assertSame('cancelled', $pending->status);
            self::assertSame(0, $pending->amount);
            self::assertSame('refunded_before_transfer', $pending->failure_code);
            self::assertSame(1, $transfers->reverseCalls);

            $service->registerDispute('ch_recovery_11', 'dp_11', 2500, 'EUR');
            $disputes = $db->table('stripe_connect_transfer_reversals')->where('kind', 'dispute')->orderBy('stripe_connect_transfer_id')->get();
            self::assertSame(['pending_review', 'pending_review'], $disputes->pluck('status')->all());
            self::assertSame([1200, 800], $disputes->pluck('amount')->all());
            self::assertSame(1, $transfers->reverseCalls, 'A dispute must not automatically reverse a seller transfer.');
        } finally {
            $this->restoreEnvironment('STRIPE_PAYMENT_FLOW', $previousFlow);
            $this->restoreEnvironment('STRIPE_CONNECT_ENABLED', $previousEnabled);
        }
    }

    public function test_keeps_a_failed_transfer_reversal_for_explicit_recovery(): void
    {
        $previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $previousEnabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? null;
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'connect';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';

        try {
            $db = new Manager();
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->bootEloquent();
            $this->createTransferTables($db);
            $this->paidTransfer($db, 11, 7, 4800, 'tr_seller_7');

            $transfers = new class {
                public bool $insufficientBalance = true;
                public int $calls = 0;

                public function createReversal(string $transferId, array $params, array $options): \Stripe\TransferReversal
                {
                    ++$this->calls;
                    if ($this->insufficientBalance) {
                        throw new \RuntimeException('Seller balance is insufficient for the reversal.');
                    }
                    return \Stripe\TransferReversal::constructFrom(['id' => 'trr_recovered']);
                }
            };
            $service = new StripeConnectService($db, null, $this->recoveryStripeClient($transfers));
            $service->reconcileRefund('pi_recovery_11', 're_recovery_11', 10000, 'EUR', 'available', new DateTimeImmutable('2026-10-22 10:00:00 UTC'));

            self::assertSame('recovery_required', $db->table('stripe_connect_transfer_reversals')->value('status'));
            self::assertSame(1, $transfers->calls);

            $transfers->insufficientBalance = false;
            self::assertSame(1, $service->retryPendingRefundReversals());
            self::assertSame('reversed', $db->table('stripe_connect_transfer_reversals')->value('status'));
            self::assertSame(2, $transfers->calls);
        } finally {
            $this->restoreEnvironment('STRIPE_PAYMENT_FLOW', $previousFlow);
            $this->restoreEnvironment('STRIPE_CONNECT_ENABLED', $previousEnabled);
        }
    }

    private function createTransferTables(Manager $db): void
    {
        foreach (['orders', 'order_detail', 'product', 'manufacturer_details', 'stripe_connect_transfer_reversals', 'stripe_connect_transfers', 'stripe_connect_accounts'] as $table) {
            $db->schema()->dropIfExists($table);
        }
        $db->schema()->create('stripe_connect_accounts', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id')->unique();
            $table->string('stripe_account_id')->unique();
            $table->string('account_type');
            $table->string('country', 2);
            $table->boolean('details_submitted');
            $table->boolean('charges_enabled');
            $table->boolean('payouts_enabled');
            $table->boolean('transfers_enabled');
            $table->text('requirements_due')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $db->schema()->create('stripe_connect_transfers', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('manufacturer_id');
            $table->string('stripe_account_id')->nullable();
            $table->string('stripe_payment_intent_id');
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_transfer_id')->nullable();
            $table->string('transfer_group');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('original_amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('failure_code')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $db->schema()->create('stripe_connect_transfer_reversals', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('stripe_connect_transfer_id');
            $table->string('kind');
            $table->string('provider_case_id');
            $table->string('stripe_transfer_reversal_id')->nullable();
            $table->unsignedInteger('source_amount');
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('failure_code')->nullable();
            $table->string('idempotency_key')->unique();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['stripe_connect_transfer_id', 'kind', 'provider_case_id']);
        });
        $db->schema()->create('manufacturer_details', function ($table): void {
            $table->unsignedInteger('id_manufacturer')->primary();
            $table->string('commission');
        });
        $db->schema()->create('product', function ($table): void {
            $table->unsignedInteger('id_product')->primary();
            $table->unsignedInteger('id_manufacturer');
        });
        $db->schema()->create('order_detail', function ($table): void {
            $table->unsignedBigInteger('id_order');
            $table->unsignedInteger('product_id');
            $table->decimal('total_price_tax_incl', 20, 6);
        });
        $db->schema()->create('orders', function ($table): void {
            $table->unsignedBigInteger('id_order')->primary();
            $table->decimal('total_shipping_tax_incl', 20, 6);
        });
    }

    private function restoreEnvironment(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            return;
        }
        $_ENV[$key] = $value;
    }

    private function paidTransfer(Manager $db, int $orderId, int $sellerId, int $amount, string $stripeTransferId): void
    {
        $this->transfer($db, $orderId, $sellerId, $amount, 'paid', $stripeTransferId);
    }

    private function pendingTransfer(Manager $db, int $orderId, int $sellerId, int $amount): void
    {
        $this->transfer($db, $orderId, $sellerId, $amount, 'pending', null);
    }

    private function transfer(Manager $db, int $orderId, int $sellerId, int $amount, string $status, ?string $stripeTransferId): void
    {
        $db->table('stripe_connect_transfers')->insert([
            'order_id' => $orderId,
            'manufacturer_id' => $sellerId,
            'stripe_account_id' => 'acct_seller_' . $sellerId,
            'stripe_payment_intent_id' => 'pi_recovery_11',
            'stripe_charge_id' => 'ch_recovery_11',
            'stripe_transfer_id' => $stripeTransferId,
            'transfer_group' => 'dz_cart_11',
            'idempotency_key' => 'transfer-' . $sellerId,
            'amount' => $amount,
            'original_amount' => $amount,
            'currency' => 'EUR',
            'status' => $status,
            'failure_code' => null,
            'created_at' => '2026-10-22 09:00:00',
            'updated_at' => '2026-10-22 09:00:00',
        ]);
    }

    private function recoveryStripeClient(object $transfers): \Stripe\StripeClient
    {
        $paymentIntents = new class {
            public function retrieve(string $id, array $params): \Stripe\PaymentIntent
            {
                return \Stripe\PaymentIntent::constructFrom([
                    'id' => $id,
                    'currency' => 'eur',
                    'amount_received' => 10000,
                ]);
            }
        };

        return new class($paymentIntents, $transfers) extends \Stripe\StripeClient {
            public function __construct(private object $paymentIntents, private object $transfers)
            {
                parent::__construct('sk_test_123');
            }

            public function getService($name)
            {
                return match ($name) {
                    'paymentIntents' => $this->paymentIntents,
                    'transfers' => $this->transfers,
                    default => parent::getService($name),
                };
            }
        };
    }

    private function reversalKey(string $kind, string $caseId, int $transferId): string
    {
        return 'connect:' . $kind . '-reversal:' . hash('sha256', $caseId . ':' . $transferId);
    }
}
