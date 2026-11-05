<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkWebhook;

require_once __DIR__ . '/../src/Service/Shipping/PacklinkWebhook.php';

final class PacklinkWebhookTest extends TestCase
{
    public function testAuthenticatedEventReturnsOnlyReference(): void
    {
        $secret = str_repeat('x', 32);
        $handler = new PacklinkWebhook($secret);
        self::assertSame('PL123', $handler->shipmentReference($secret, '{"event":"shipment.tracking.update","datetime":"2026-01-01T00:00:00Z","data":{"shipment_reference":"PL123","state":"ignored"}}'));
    }

    public function testRejectsUnauthenticatedRequest(): void
    {
        $handler = new PacklinkWebhook(str_repeat('x', 32));
        $this->expectException(UnexpectedValueException::class);
        $handler->shipmentReference('bad', '{}');
    }

    public function testRejectsUnknownEvent(): void
    {
        $secret = str_repeat('x', 32);
        $handler = new PacklinkWebhook($secret);
        $this->expectException(InvalidArgumentException::class);
        $handler->shipmentReference($secret, '{"event":"unknown","datetime":"2026-01-01","data":{"shipment_reference":"PL123"}}');
    }
}
