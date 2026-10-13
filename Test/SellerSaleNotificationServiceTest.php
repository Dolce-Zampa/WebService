<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Service\Financial\SellerSaleNotificationService;
use PS\Webservice\Service\MailerInterface;
use PHPUnit\Framework\TestCase;

final class SellerSaleNotificationServiceTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DB();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $schema = $this->db->schema();

        $schema->create('orders', function ($table): void {
            $table->unsignedInteger('id_order')->primary();
            $table->unsignedInteger('id_cart');
            $table->string('reference', 64);
            $table->unsignedInteger('current_state');
            $table->dateTime('date_add');
        });
        $schema->create('order_detail', function ($table): void {
            $table->increments('id_order_detail');
            $table->unsignedInteger('id_order');
            $table->unsignedInteger('product_id');
            $table->string('product_name');
            $table->unsignedInteger('product_quantity');
            $table->decimal('total_price_tax_incl', 20, 6)->nullable();
            $table->decimal('unit_price_tax_incl', 20, 6)->nullable();
        });
        $schema->create('product', function ($table): void {
            $table->unsignedInteger('id_product')->primary();
            $table->unsignedInteger('id_manufacturer');
        });
        $schema->create('manufacturer', function ($table): void {
            $table->unsignedInteger('id_manufacturer')->primary();
            $table->string('email')->nullable();
            $table->string('name');
        });
        $schema->create('seller_sale_notification_logs', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('id_order');
            $table->unsignedBigInteger('id_manufacturer');
            $table->string('order_reference', 64);
            $table->string('status', 16);
            $table->unsignedInteger('attempts');
            $table->string('last_error', 191)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['id_order', 'id_manufacturer']);
        });

        DB::table('orders')->insert([
            'id_order' => 501,
            'id_cart' => 701,
            'reference' => 'ORDER-501',
            'current_state' => 2,
            'date_add' => '2026-10-09 09:00:00',
        ]);
        DB::table('manufacturer')->insert([
            ['id_manufacturer' => 11, 'email' => 'one@example.test', 'name' => 'Venditore uno'],
            ['id_manufacturer' => 12, 'email' => 'two@example.test', 'name' => 'Venditore due'],
        ]);
        DB::table('product')->insert([
            ['id_product' => 101, 'id_manufacturer' => 11],
            ['id_product' => 102, 'id_manufacturer' => 12],
        ]);
        DB::table('order_detail')->insert([
            ['id_order' => 501, 'product_id' => 101, 'product_name' => 'Prodotto uno', 'product_quantity' => 2, 'total_price_tax_incl' => '24.00', 'unit_price_tax_incl' => '12.00'],
            ['id_order' => 501, 'product_id' => 102, 'product_name' => 'Prodotto due', 'product_quantity' => 1, 'total_price_tax_incl' => '8.50', 'unit_price_tax_incl' => '8.50'],
        ]);
    }

    public function test_sends_each_seller_only_its_own_items_and_is_idempotent(): void
    {
        $deliveries = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('sendSellerSaleNotification')
            ->willReturnCallback(function (
                string $email,
                string $sellerName,
                string $orderReference,
                string $finalizedAt,
                array $items,
                string $amount,
                string $status,
            ) use (&$deliveries): void {
                $deliveries[$email] = compact('sellerName', 'orderReference', 'finalizedAt', 'items', 'amount', 'status');
            });

        $service = new SellerSaleNotificationService($this->db, $mailer);
        $service->notifyFinalizedSalesForCart(701, new DateTimeImmutable('2026-10-09 10:30:00'));
        $service->notifyFinalizedSalesForCart(701, new DateTimeImmutable('2026-10-09 10:30:00'));

        self::assertSame('24.00', $deliveries['one@example.test']['amount']);
        self::assertSame([['name' => 'Prodotto uno', 'quantity' => 2, 'amount' => '24.00']], $deliveries['one@example.test']['items']);
        self::assertSame('8.50', $deliveries['two@example.test']['amount']);
        self::assertSame([['name' => 'Prodotto due', 'quantity' => 1, 'amount' => '8.50']], $deliveries['two@example.test']['items']);
        self::assertSame('payment_accepted', $deliveries['one@example.test']['status']);
        self::assertSame('2026-10-09 10:30:00', $deliveries['one@example.test']['finalizedAt']);
        self::assertSame(2, DB::table('seller_sale_notification_logs')->where('status', 'sent')->count());
        self::assertSame(1, DB::table('seller_sale_notification_logs')->where('id_manufacturer', 11)->value('attempts'));
    }

    public function test_failed_delivery_is_retried_without_creating_another_journal_row(): void
    {
        $failingMailer = $this->createMock(MailerInterface::class);
        $failingMailer->expects(self::exactly(2))
            ->method('sendSellerSaleNotification')
            ->willThrowException(new RuntimeException('mail provider unavailable'));
        (new SellerSaleNotificationService($this->db, $failingMailer))->notifyFinalizedSalesForCart(701);

        self::assertSame(2, DB::table('seller_sale_notification_logs')->where('status', 'failed')->count());
        self::assertSame(RuntimeException::class, DB::table('seller_sale_notification_logs')->where('id_manufacturer', 11)->value('last_error'));

        $recoveredMailer = $this->createMock(MailerInterface::class);
        $recoveredMailer->expects(self::exactly(2))->method('sendSellerSaleNotification');
        (new SellerSaleNotificationService($this->db, $recoveredMailer))->retryFailedNotifications();

        self::assertSame(2, DB::table('seller_sale_notification_logs')->count());
        self::assertSame(2, DB::table('seller_sale_notification_logs')->where('status', 'sent')->count());
        self::assertSame(2, DB::table('seller_sale_notification_logs')->where('id_manufacturer', 11)->value('attempts'));
        self::assertNull(DB::table('seller_sale_notification_logs')->where('id_manufacturer', 11)->value('last_error'));
    }
}
