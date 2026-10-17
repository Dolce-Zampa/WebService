<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Payments\StripeConnectService;

final class StripeConnectServiceTest extends TestCase
{
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
            public array $createParams = [];

            public function create(array $params, array $options): \Stripe\Account
            {
                ++$this->createCalls;
                $this->createParams = $params;
                return $this->account();
            }

            public function retrieve(string $id, array $params): \Stripe\Account
            {
                return $this->account();
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
        self::assertSame(['transfers' => ['requested' => true]], $accounts->createParams['capabilities']);
        self::assertSame('7', $accounts->createParams['metadata']['manufacturer_id']);
    }
}
