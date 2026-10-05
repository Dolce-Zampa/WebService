<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Object\ConfirmOrderSession;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

final class ConfirmOrderSessionTest extends TestCase
{
    private function createSession(string $paymentModule): ConfirmOrderSession
    {
        return ConfirmOrderSession::create([
            'id_cart' => 42,
            'id_customer' => 7,
            'id_guest' => null,
            'order_state' => 2,
            'amount_paid' => 10,
            'payment_module' => $paymentModule,
        ], $this->createMock(PrestashopServiceInterface::class));
    }

    public function test_selected_payment_module_is_in_the_confirmation_payload(): void
    {
        $session = $this->createSession('webserviceapi');

        self::assertSame('webserviceapi', $session->payment_module);
        self::assertSame([], $session->validate());
    }

    public function test_invalid_payment_module_is_rejected(): void
    {
        $session = $this->createSession('unknown module');

        self::assertContains('payment_module must be a configured module name', $session->validate());
    }
}
