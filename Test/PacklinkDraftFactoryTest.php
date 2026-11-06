<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkDraftFactory;

require_once __DIR__ . '/../src/Service/Shipping/PacklinkDraftFactory.php';

final class PacklinkDraftFactoryTest extends TestCase
{
    private function address(): array
    {
        return ['name' => 'A', 'surname' => 'B', 'street1' => 'Street', 'zip_code' => '00100', 'city' => 'Roma', 'country' => 'IT', 'phone' => '123', 'email' => 'a@example.test'];
    }

    private function configuration(): array
    {
        return ['user_id' => 'user', 'client_id' => 'client', 'platform' => 'PL', 'platform_country' => 'IT', 'source' => 'shop', 'service_id' => 'service'];
    }

    private function order(): array
    {
        return ['from' => $this->address(), 'to' => $this->address(), 'packages' => [['weight' => 1.25, 'width' => 3.2, 'length' => 8, 'height' => 2]], 'content' => 'Products', 'content_value' => 20.55, 'currency' => 'EUR', 'dropoff_point_id' => 'P123'];
    }

    public function testProducesPacklinkDraftWithoutInventingPointOrMeasurements(): void
    {
        $draft = (new PacklinkDraftFactory())->build(123, $this->order(), $this->configuration());
        self::assertSame('order-123', $draft['shipment_custom_reference']);
        self::assertSame('123', $draft['additional_data']['order_id']);
        self::assertSame('P123', $draft['dropoff_point_id']);
        self::assertSame(['weight' => 1.25, 'width' => 4, 'height' => 2, 'length' => 8], $draft['packages'][0]);
        self::assertSame(20.55, $draft['contentvalue']);
    }

    public function testMissingPackageDataFailsBeforeSending(): void
    {
        $order = $this->order();
        $order['packages'][0]['weight'] = 0;
        $this->expectException(InvalidArgumentException::class);
        (new PacklinkDraftFactory())->build(123, $order, $this->configuration());
    }
}
