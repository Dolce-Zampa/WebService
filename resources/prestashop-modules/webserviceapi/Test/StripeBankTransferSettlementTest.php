<?php
use PHPUnit\Framework\TestCase;

class ModuleFrontController {}
class Configuration
{
    public static function get($key) { return ['PS_OS_BANKWIRE' => 10, 'PS_OS_PAYMENT' => 2, 'PS_OS_OUTOFSTOCK_UNPAID' => 9][$key] ?? null; }
}
class Validate { public static function isLoadedObject($object) { return !empty($object->id); } }
class Db
{
    public static array $rows = [['id_order' => 7]];
    public static function getInstance() { return new self(); }
    public function executeS($sql) { return self::$rows; }
}
class Order
{
    public static array $orders = [];
    public $id;
    public $payment;
    public $current_state;
    public $total_paid_tax_incl;
    public function __construct($id) {
        $this->id = $id;
        $data = self::$orders[$id];
        $this->payment = $data['payment'];
        $this->current_state = $data['state'];
        $this->total_paid_tax_incl = $data['total'];
    }
    public function hasInvoice() { return self::$orders[$this->id]['invoice']; }
    public function hasBeenPaid() { return self::$orders[$this->id]['was_paid'] ?? false; }
    public function getOrderPayments() { return self::$orders[$this->id]['payments']; }
    public function addOrderPayment($amount, $method, $intent) {
        self::$orders[$this->id]['payments'][] = (object) ['transaction_id' => $intent, 'amount' => $amount];
        return true;
    }
    public function setCurrentState($state) { self::$orders[$this->id]['state'] = $state; }
}
if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'ps_'); }
require_once __DIR__ . '/../controllers/front/order.php';

final class StripeBankTransferSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        Db::$rows = [['id_order' => 7]];
        Order::$orders = [7 => ['payment' => 'Bonifico bancario (Stripe)', 'state' => 10, 'total' => 95.10, 'invoice' => false, 'payments' => []]];
    }
    private function settle(): array
    {
        $method = new ReflectionMethod(webserviceapiorderModuleFrontController::class, 'settleStripeBankTransfer');
        return $method->invoke(new webserviceapiorderModuleFrontController(), ['id_cart' => 833, 'payment_intent' => 'pi_test123']);
    }
    public function test_payment_and_state_are_recorded_once_on_retry(): void
    {
        self::assertSame(['action' => 'settle_stripe_bank_transfer'], $this->settle());
        $this->settle();
        self::assertSame(2, Order::$orders[7]['state']);
        self::assertCount(1, Order::$orders[7]['payments']);
        self::assertSame(95.10, Order::$orders[7]['payments'][0]->amount);
    }
    public function test_retry_after_payment_recorded_finishes_state_change(): void
    {
        Order::$orders[7]['payments'][] = (object) ['transaction_id' => 'pi_test123', 'amount' => 95.10];
        $this->settle();
        self::assertSame(2, Order::$orders[7]['state']);
        self::assertCount(1, Order::$orders[7]['payments']);
    }
    public function test_cancelled_order_is_not_marked_paid(): void
    {
        Order::$orders[7]['state'] = 6;
        $this->expectException(MlabFactoryApiException::class);
        $this->settle();
    }
    public function test_retry_does_not_roll_back_an_order_that_has_progressed(): void
    {
        Order::$orders[7]['state'] = 4;
        Order::$orders[7]['was_paid'] = true;
        Order::$orders[7]['payments'][] = (object) ['transaction_id' => 'pi_test123', 'amount' => 95.10];
        $this->settle();
        self::assertSame(4, Order::$orders[7]['state']);
        self::assertCount(1, Order::$orders[7]['payments']);
    }
    public function test_multiple_orders_require_manual_reconciliation(): void
    {
        Db::$rows[] = ['id_order' => 8];
        $this->expectException(MlabFactoryApiException::class);
        $this->settle();
    }
}
