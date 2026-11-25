<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Payments\StripeConnectService;

final class StripeConnectTransferLedgerTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_transfer_is_recorded_once_without_claiming_a_bank_payout(): void
    {
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('financial_transactions', function ($table): void {
            $table->increments('id');
            foreach (['order_id', 'customer_id', 'artisan_id', 'order_item_id', 'related_transaction_id'] as $column) $table->unsignedBigInteger($column)->nullable();
            foreach (['order_reference', 'artisan_reference', 'order_item_reference', 'provider', 'provider_account_id', 'provider_transaction_id', 'provider_event_id', 'source_event_type', 'source_event_id', 'metadata'] as $column) $table->text($column)->nullable();
            $table->string('type'); $table->string('status'); $table->decimal('amount', 20, 6); $table->string('currency');
            $table->dateTime('occurred_at'); $table->dateTime('available_at')->nullable(); $table->dateTime('settled_at')->nullable(); $table->timestamps();
        });
        $db->schema()->create('financial_transaction_events', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('financial_transaction_id');
            foreach (['event_type', 'status', 'idempotency_key'] as $column) $table->string($column);
            foreach (['source_event_type', 'source_event_id', 'metadata'] as $column) $table->text($column)->nullable();
            foreach (['occurred_at', 'received_at', 'created_at'] as $column) $table->dateTime($column);
            $table->unique('idempotency_key');
        });
        $ledger = new FinancialLedgerService(new FinancialTransactionRepository($db));
        $service = new StripeConnectService($db, $ledger, new \Stripe\StripeClient('sk_test_example'));
        $method = new ReflectionMethod(StripeConnectService::class, 'recordTransfer');
        $args = [42, ['manufacturer_id' => 7, 'amount' => 1299], (object) ['stripe_account_id' => 'acct_1'],
            \Stripe\Transfer::constructFrom(['id' => 'tr_1']), 'pi_1', new DateTimeImmutable('2026-11-25 12:00:00 UTC'), 'EUR'];
        $method->invokeArgs($service, $args);
        $method->invokeArgs($service, $args);
        self::assertSame(1, $db->table('financial_transactions')->count());
        $movement = $db->table('financial_transactions')->first();
        self::assertSame('transfer', $movement->type);
        self::assertSame('available', $movement->status);
        self::assertNull($movement->settled_at);
        self::assertSame('tr_1', $movement->provider_transaction_id);
        self::assertSame(0, $db->table('financial_transactions')->where('type', 'payout')->count());
    }
}
