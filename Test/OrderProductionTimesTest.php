<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../resources/prestashop-modules/webserviceapi/classes/MlabOrderProductionTimes.php';

final class OrderProductionTimesTest extends TestCase
{
    public function testFormatsEachProductAndSellerForBothMailFormats(): void
    {
        [$html, $text] = MlabOrderProductionTimes::format([
            ['name' => 'Collare <rosso>', 'seller' => 'Artigiano & Figli', 'available_now' => '5 giorni'],
            ['name' => 'Cappotto', 'seller' => 'Maria', 'available_now' => '1 giorno'],
            ['name' => 'Guinzaglio', 'seller' => 'Anna', 'available_now' => ''],
        ]);

        self::assertStringContainsString('Collare &lt;rosso&gt; — Artigiano &amp; Figli: 5 giorni', $html);
        self::assertStringContainsString('Collare <rosso> — Artigiano & Figli: 5 giorni', $text);
        self::assertStringContainsString('Cappotto — Maria: 1 giorno', $html);
        self::assertStringContainsString('Guinzaglio — Anna: non disponibile', $text);
    }
}
