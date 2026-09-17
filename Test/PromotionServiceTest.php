<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Domain\Models\PS\Products\ProductPromotion;
use PS\Webservice\Domain\Models\PS\Products\PromotionPackage;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\Promotions\PromotionService;
use PHPUnit\Framework\TestCase;

final class PromotionServiceTest extends TestCase
{
    private PromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = DB::schema();
        foreach (['product_promotion_stats', 'product_promotions', 'promotion_packages'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }

        $schema->create('promotion_packages', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('duration_days');
            $table->decimal('price', 10, 2);
            $table->string('position')->default('homepage');
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('product_promotions', function ($table): void {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('seller_id');
            $table->integer('package_id');
            $table->decimal('price', 10, 2);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->string('status')->default('pending');
            $table->string('stripe_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('product_promotion_stats', function ($table): void {
            $table->increments('id');
            $table->integer('promotion_id')->unique();
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $this->service = new PromotionService($this->createMock(Product::class));
    }

    public function test_detects_promotion_checkout_session_by_metadata(): void
    {
        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_1',
            'metadata' => [
                'seller_id' => '10',
                'product_id' => '12',
                'promotion_package_id' => '1',
                'promotion_id' => '7',
            ],
        ]);

        $this->assertTrue($this->service->isPromotionCheckoutSession($session));
    }

    public function test_activates_promotion_after_successful_payment(): void
    {
        $package = PromotionPackage::query()->create([
            'name' => 'Boost 7 giorni',
            'duration_days' => 7,
            'price' => 5.00,
            'position' => 'homepage',
            'active' => 1,
        ]);

        $promotion = ProductPromotion::query()->create([
            'product_id' => 100,
            'seller_id' => 50,
            'package_id' => (int) $package->id,
            'price' => 5.00,
            'status' => 'pending',
        ]);

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_prom_1',
            'currency' => 'eur',
            'amount_total' => 500,
            'payment_intent' => 'pi_123',
            'metadata' => [
                'seller_id' => '50',
                'product_id' => '100',
                'promotion_package_id' => (string) $package->id,
                'promotion_id' => (string) $promotion->id,
            ],
        ]);

        $updated = $this->service->activatePromotionFromStripeSession($session);

        $this->assertNotNull($updated);
        $this->assertSame('active', $updated->status);
        $this->assertNotNull($updated->start_date);
        $this->assertNotNull($updated->end_date);
        $this->assertSame('cs_prom_1', $updated->stripe_session_id);
        $this->assertSame('pi_123', $updated->stripe_payment_intent_id);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $package = PromotionPackage::query()->create([
            'name' => 'Boost 14 giorni',
            'duration_days' => 14,
            'price' => 9.90,
            'position' => 'category',
            'active' => 1,
        ]);

        $promotion = ProductPromotion::query()->create([
            'product_id' => 200,
            'seller_id' => 90,
            'package_id' => (int) $package->id,
            'price' => 9.90,
            'status' => 'pending',
        ]);

        $session = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_prom_dup',
            'currency' => 'eur',
            'amount_total' => 990,
            'metadata' => [
                'seller_id' => '90',
                'product_id' => '200',
                'promotion_package_id' => (string) $package->id,
                'promotion_id' => (string) $promotion->id,
            ],
        ]);

        $firstActivation = $this->service->activatePromotionFromStripeSession($session);
        $secondActivation = $this->service->activatePromotionFromStripeSession($session);

        $this->assertNotNull($firstActivation);
        $this->assertNotNull($secondActivation);
        $this->assertSame('active', $secondActivation->status);

        $stored = ProductPromotion::query()->find((int) $promotion->id);
        $this->assertNotNull($stored);
        $this->assertSame('active', $stored->status);
        $this->assertSame('cs_prom_dup', $stored->stripe_session_id);
    }
}
