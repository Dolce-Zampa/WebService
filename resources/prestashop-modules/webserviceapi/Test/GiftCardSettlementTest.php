<?php
use PHPUnit\Framework\TestCase;

class ModuleFrontController {}
class Configuration { public static function get($key) { return $key === 'PS_OS_OUTOFSTOCK_UNPAID' ? 9 : null; } }
class Validate { public static function isLoadedObject($object) { return !empty($object->id); } }
class OrderState
{
    public $id, $paid, $logable, $invoice = false;
    public function __construct($id) { $this->id = $id; $this->paid = $id === 2; $this->logable = $id === 2; }
}
class Currency { public $iso_code = 'EUR'; public function __construct($id) {} }
class Db
{
    public static array $snapshot = [], $reservation = [], $rows = [['id_order' => 7]];
    public static function getInstance() { return new self(); }
    public function getValue($sql) { return 1; }
    public function getRow($sql) { return str_contains($sql, 'mlabgc_reservations') ? self::$reservation : self::$snapshot; }
    public function executeS($sql) { return self::$rows; }
    public function execute($sql) { self::$snapshot['gift_card_settled_at'] = '2026-11-11 09:00:00'; return true; }
}
class Order
{
    public static array $record = [];
    public static ?int $failPaymentNumber = null;
    public $id, $id_shop = 1, $id_currency = 1, $current_state, $total_paid_tax_incl;
    public function __construct($id) { $this->id = $id; $this->current_state = self::$record['state']; $this->total_paid_tax_incl = self::$record['total']; }
    public function hasInvoice() { return false; }
    public function getOrderPayments() { return self::$record['payments']; }
    public function addOrderPayment($amount, $method, $reference) {
        if (self::$failPaymentNumber === count(self::$record['payments']) + 1) { self::$failPaymentNumber = null; return false; }
        self::$record['payments'][] = (object) ['transaction_id' => $reference, 'amount' => $amount]; return true;
    }
    public function setCurrentState($state) { self::$record['state'] = $state; }
}
function pSQL($value) { return addslashes($value); }
if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'ps_'); }
require_once __DIR__ . '/../controllers/front/order.php';

final class GiftCardSettlementTest extends TestCase
{
    private string $reservation = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $snapshot = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $gift = ['reservation_id' => $this->reservation, 'gift_card_cents' => 2000, 'stripe_cents' => 1000];
        Db::$snapshot = ['gift_card_payment_verified_at' => '2026-11-11 09:00:00', 'gift_card_reservation_id' => $this->reservation,
            'stripe_payment_intent_id' => 'pi_test123', 'verification_payload' => json_encode(['gift_card' => $gift,
                'currency' => 'EUR', 'totals' => ['grand_total_cents' => 3000]])];
        Db::$reservation = ['status' => 'captured', 'order_id' => 7, 'cart_id' => 42, 'amount_cents' => 2000, 'refunded_cents' => 0];
        Db::$rows = [['id_order' => 7]];
        Order::$record = ['state' => 21, 'total' => 30.0, 'payments' => []];
        Order::$failPaymentNumber = null;
    }

    private function settle(): array
    {
        $method = new ReflectionMethod(webserviceapiorderModuleFrontController::class, 'settleGiftCard');
        return $method->invoke(new webserviceapiorderModuleFrontController(), [
            'id_cart' => 42, 'checkout_snapshot_id' => $this->snapshot, 'pending_state_id' => 21,
        ]);
    }

    public function test_partial_payment_is_recorded_once_and_the_order_is_confirmed(): void
    {
        $this->settle();
        $this->settle();
        self::assertSame(2, Order::$record['state']);
        self::assertCount(2, Order::$record['payments']);
        self::assertSame(20, Order::$record['payments'][0]->amount);
        self::assertSame(10, Order::$record['payments'][1]->amount);
    }

    public function test_fully_covered_order_requires_no_stripe_payment(): void
    {
        $payload = json_decode(Db::$snapshot['verification_payload'], true);
        $payload['gift_card']['gift_card_cents'] = 3000;
        $payload['gift_card']['stripe_cents'] = 0;
        Db::$snapshot['verification_payload'] = json_encode($payload);
        Db::$snapshot['stripe_payment_intent_id'] = null;
        Db::$reservation['amount_cents'] = 3000;
        $this->settle();
        self::assertSame(2, Order::$record['state']);
        self::assertCount(1, Order::$record['payments']);
        self::assertSame(30, Order::$record['payments'][0]->amount);
    }

    public function test_retry_recovers_if_recording_the_second_payment_fails(): void
    {
        Order::$failPaymentNumber = 2;
        try { $this->settle(); self::fail('Expected settlement failure'); } catch (MlabFactoryApiException $error) {}
        self::assertSame(21, Order::$record['state']);
        self::assertCount(1, Order::$record['payments']);
        $this->settle();
        self::assertSame(2, Order::$record['state']);
        self::assertCount(2, Order::$record['payments']);
    }

    public function test_uncaptured_reservation_cannot_confirm_order(): void
    {
        Db::$reservation['status'] = 'held';
        $this->expectException(MlabFactoryApiException::class);
        try { $this->settle(); } finally { self::assertSame(21, Order::$record['state']); }
    }
}
