<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PurchaseProtection\CartPaymentGuard;

final class CartPaymentGuardTest extends TestCase
{
    private function database(): Manager
    {
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
            $table->integer('cart_id');
            $table->string('checkout_session_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->string('gift_card_reservation_id')->nullable();
            $table->dateTime('gift_card_payment_verified_at')->nullable();
            $table->dateTime('gift_card_released_at')->nullable();
        });
        return $db;
    }

    public function testExpiresEveryOldUnpaidSessionForTheSameCart(): void
    {
        $db = $this->database();
        $db->table('stripe_checkout_snapshots')->insert([
            ['cart_id' => 42, 'checkout_session_id' => 'cs_first'],
            ['cart_id' => 42, 'checkout_session_id' => 'cs_second'],
            ['cart_id' => 99, 'checkout_session_id' => 'cs_other'],
        ]);
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::exactly(2))->method('expireUnpaidSession');
        (new CartPaymentGuard($db, $gateway))->retireUnpaidSessions(42);
    }

    /** @dataProvider blockedSnapshots */
    public function testDoesNotMutateCartWhenPreviousCheckoutIsUncertain(array $snapshot): void
    {
        $db = $this->database();
        $db->table('stripe_checkout_snapshots')->insert(['cart_id' => 42] + $snapshot);
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('expireUnpaidSession');
        $this->expectException(RuntimeException::class);
        (new CartPaymentGuard($db, $gateway))->retireUnpaidSessions(42);
    }

    public static function blockedSnapshots(): array
    {
        return [
            'unknown session' => [['checkout_session_id' => null]],
            'paid order' => [['checkout_session_id' => 'cs_paid', 'order_id' => 7]],
            'active gift card' => [['checkout_session_id' => 'cs_gift', 'gift_card_reservation_id' => 'hold']],
            'verified gift card' => [['checkout_session_id' => 'cs_gift', 'gift_card_payment_verified_at' => '2026-01-01 00:00:00']],
        ];
    }
}
