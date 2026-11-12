<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Payments\StripeConnectModule;

final class StripeConnectModuleTest extends TestCase
{
    private mixed $previousFlow;
    private mixed $previousEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $this->previousEnabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restore('STRIPE_PAYMENT_FLOW', $this->previousFlow);
        $this->restore('STRIPE_CONNECT_ENABLED', $this->previousEnabled);
        parent::tearDown();
    }

    public function test_legacy_checkout_remains_default_when_connect_is_not_selected(): void
    {
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'standard';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';

        self::assertFalse(StripeConnectModule::enabled());
    }

    public function test_connect_requires_an_explicit_second_activation_switch(): void
    {
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'connect';
        $_ENV['STRIPE_CONNECT_ENABLED'] = 'false';
        self::assertFalse(StripeConnectModule::enabled());

        $_ENV['STRIPE_CONNECT_ENABLED'] = 'true';
        self::assertTrue(StripeConnectModule::enabled());
    }

    private function restore(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            return;
        }
        $_ENV[$key] = $value;
    }
}
