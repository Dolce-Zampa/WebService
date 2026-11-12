<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Object\ConfirmOrderSession;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Order;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StripeBankTransferStateTest extends TestCase
{
    private Manager $db;

    protected function setUp(): void
    {
        $this->db = new Manager();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->db->schema()->create('configuration', function ($table): void {
            $table->increments('id_configuration');
            $table->string('name');
            $table->string('value');
        });
        $this->db->schema()->create('order_state', function ($table): void {
            $table->integer('id_order_state')->primary();
            $table->integer('paid');
            $table->integer('logable');
            $table->integer('invoice');
        });
        $this->db->table('configuration')->insert(['name' => 'PS_OS_PAYMENT', 'value' => '2']);
        $this->db->table('order_state')->insert(['id_order_state' => 21, 'paid' => 0, 'logable' => 0, 'invoice' => 0]);
        unset($_ENV['STRIPE_BANK_TRANSFER_PENDING_STATE_ID']);
        putenv('STRIPE_BANK_TRANSFER_PENDING_STATE_ID');
    }

    public function test_sends_configured_pending_state_to_legacy_order_endpoint(): void
    {
        $service = new class($this->createMock(HttpServiceInterface::class)) extends Order {
            public ?ConfirmOrderSession $confirmation = null;
            protected function confirmOrder(ConfirmOrderSession $session): void { $this->confirmation = $session; }
        };
        $customer = (object) ['payment_module' => 'webserviceapi'];
        $service->confirmSessionOrder(833, null, 563, 15, null, 'buyer@example.test', 'Buyer', 'Test', $customer, 95.10, 'Bonifico bancario (Stripe)', null, true);
        self::assertSame(21, $service->confirmation->id_order_state);
        self::assertTrue($service->confirmation->stripe_bank_transfer_pending);
        self::assertSame([], $service->confirmation->validate());
    }

    public function test_refuses_a_pending_state_configured_as_paid(): void
    {
        $this->db->table('order_state')->where('id_order_state', 21)->update(['paid' => 1]);
        $service = new Order($this->createMock(HttpServiceInterface::class));
        $this->expectException(RuntimeException::class);
        $service->confirmSessionOrder(833, null, 563, 15, null, 'buyer@example.test', 'Buyer', 'Test', (object) [], 95.10, 'Bonifico bancario (Stripe)', null, true);
    }
}
