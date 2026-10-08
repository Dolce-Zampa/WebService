<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;

final class FinancialLedgerServiceTest extends TestCase
{
    private FinancialLedgerService $service;
    private FinancialTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new DB();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $schema = $capsule->schema();
        $schema->dropIfExists('financial_transaction_events');
        $schema->dropIfExists('financial_transactions');

        $schema->create('financial_transactions', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('artisan_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('order_reference', 64)->nullable();
            $table->string('artisan_reference', 191)->nullable();
            $table->string('order_item_reference', 191)->nullable();
            $table->string('type', 32);
            $table->string('status', 16);
            $table->decimal('amount', 20, 6);
            $table->char('currency', 3);
            $table->string('provider', 64)->nullable();
            $table->string('provider_account_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->string('source_event_type', 80)->nullable();
            $table->string('source_event_id', 191)->nullable();
            $table->unsignedBigInteger('related_transaction_id')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('available_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });
        $schema->create('financial_transaction_events', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('financial_transaction_id');
            $table->string('event_type', 32);
            $table->string('status', 16);
            $table->string('idempotency_key', 191)->unique();
            $table->string('source_event_type', 80)->nullable();
            $table->string('source_event_id', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->dateTime('created_at');
        });

        $this->repository = new FinancialTransactionRepository($capsule);
        $this->service = new FinancialLedgerService($this->repository);
    }

    public function test_records_a_movement_once_when_the_same_event_is_retried(): void
    {
        $movement = $this->movement([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_123',
            'source_event_type' => 'checkout.completed',
            'source_event_id' => 'evt_123',
        ]);

        $first = $this->service->record($movement, 'stripe:evt_123');
        $retry = $this->service->record($movement, 'stripe:evt_123');

        self::assertTrue($first->created);
        self::assertFalse($retry->created);
        self::assertSame($first->transactionId, $retry->transactionId);
        self::assertSame(1, DB::table('financial_transactions')->count());
        self::assertSame(1, DB::table('financial_transaction_events')->count());

        $event = DB::table('financial_transaction_events')->first();
        self::assertSame('recorded', $event->event_type);
        self::assertSame('checkout.completed', $event->source_event_type);
        self::assertNotEmpty($event->received_at);
    }

    public function test_provider_unique_event_deduplicates_retries_with_a_different_request_key(): void
    {
        $movement = $this->movement(['provider' => 'stripe', 'provider_event_id' => 'evt_unique']);

        $first = $this->service->record($movement, 'request-one');
        $retry = $this->service->record($movement, 'request-two');

        self::assertTrue($first->created);
        self::assertFalse($retry->created);
        self::assertSame($first->transactionId, $retry->transactionId);
        self::assertSame(1, DB::table('financial_transactions')->count());
    }

    public function test_status_history_is_appended_and_transitions_are_validated(): void
    {
        $recorded = $this->service->record($this->movement(), 'record-pending');

        $available = $this->service->changeStatus(
            $recorded->transactionId,
            'available',
            'make-available',
            new DateTimeImmutable('2026-10-08 11:00:00'),
            'provider.webhook',
            'wh_1',
        );
        $retry = $this->service->changeStatus(
            $recorded->transactionId,
            'available',
            'make-available',
            new DateTimeImmutable('2026-10-08 11:00:00'),
            'provider.webhook',
            'wh_1',
        );

        self::assertTrue($available->created);
        self::assertFalse($retry->created);
        self::assertSame('pending', DB::table('financial_transactions')->where('id', $recorded->transactionId)->value('status'));
        self::assertSame(['recorded', 'status_changed'], $this->repository->eventsForTransaction($recorded->transactionId)->pluck('event_type')->all());

        $this->expectException(DomainException::class);
        $this->service->changeStatus(
            $recorded->transactionId,
            'cancelled',
            'cancel-after-available',
            new DateTimeImmutable('2026-10-08 12:00:00'),
        );
    }

    public function test_correction_creates_a_linked_movement_without_changing_the_original(): void
    {
        $original = $this->service->record($this->movement(['amount' => '18.500000']), 'payment-1');
        $correction = $this->service->recordCorrection(
            $original->transactionId,
            $this->movement(['type' => 'adjustment', 'amount' => '-2.000000']),
            'adjustment-1',
        );

        self::assertTrue($correction->created);
        self::assertSame('18.5', (string) DB::table('financial_transactions')->where('id', $original->transactionId)->value('amount'));

        $linked = DB::table('financial_transactions')->where('id', $correction->transactionId)->first();
        self::assertSame($original->transactionId, (int) $linked->related_transaction_id);
        self::assertSame('adjustment', $linked->type);
        self::assertSame('correction', DB::table('financial_transaction_events')->where('id', $correction->eventId)->value('event_type'));
    }

    public function test_order_and_artisan_ledgers_are_returned_chronologically(): void
    {
        $later = $this->service->record($this->movement(['amount' => '20.00', 'occurred_at' => '2026-10-08 14:00:00']), 'later');
        $earlier = $this->service->record($this->movement(['amount' => '10.00', 'occurred_at' => '2026-10-08 09:00:00']), 'earlier');

        $forOrder = $this->repository->chronologicalForOrder(101);
        $forArtisan = $this->repository->chronologicalForArtisan(44);

        self::assertSame([$earlier->transactionId, $later->transactionId], $forOrder->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame([$earlier->transactionId, $later->transactionId], $forArtisan->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    /** @param array<string, mixed> $overrides */
    private function movement(array $overrides = []): FinancialMovement
    {
        return FinancialMovement::fromArray(array_merge([
            'type' => 'payment',
            'status' => 'pending',
            'amount' => '20.000000',
            'currency' => 'EUR',
            'occurred_at' => '2026-10-08 10:00:00',
            'order_id' => 101,
            'artisan_id' => 44,
            'order_reference' => 'ORD-101',
            'artisan_reference' => 'ART-44',
        ], $overrides));
    }
}
