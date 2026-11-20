<?php

use PHPUnit\Framework\TestCase;

if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_', 'ps_');
if (!function_exists('pSQL')) { function pSQL($value) { return str_replace("'", "''", $value); } }
if (!class_exists('Configuration')) {
    class Configuration { public static function get($key) { return ['PS_OS_CANCELED' => 6, 'PS_OS_REFUND' => 7][$key]; } }
}
require_once __DIR__ . '/../classes/DzOrderMarginRepository.php';

final class DzOrderMarginTestDb
{
    public $pdo;
    public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function executeS($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function getValue($sql)
    {
        if (strpos($sql, 'information_schema.tables') !== false) {
            preg_match("/table_name = '([^']+)'/", $sql, $match);
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$match[1]]);
            return $statement->fetchColumn();
        }
        return $this->pdo->query($sql)->fetchColumn();
    }
    public function execute($sql)
    {
        $sql = str_replace('ON DUPLICATE KEY UPDATE `fee_override` = VALUES(`fee_override`)', 'ON CONFLICT(`id_order`) DO UPDATE SET `fee_override` = excluded.`fee_override`', $sql);
        return $this->pdo->exec($sql) !== false;
    }
}

final class DzOrderMarginRepositoryTest extends TestCase
{
    private $db;
    private $repository;

    protected function setUp(): void
    {
        $this->db = new DzOrderMarginTestDb();
        $sql = [
            'CREATE TABLE ps_orders (id_order INTEGER, id_shop INTEGER, id_currency INTEGER, reference TEXT, date_add TEXT, current_state INTEGER, valid INTEGER, payment TEXT, total_paid_tax_incl TEXT, total_shipping_tax_incl TEXT, total_wrapping_tax_incl TEXT)',
            'CREATE TABLE ps_dz_order_margin (id_order INTEGER PRIMARY KEY, fee_override TEXT)',
            'CREATE VIEW ps_dz_order_margin_view AS SELECT o.*, m.fee_override FROM ps_orders o LEFT JOIN ps_dz_order_margin m ON m.id_order = o.id_order',
            'CREATE TABLE ps_currency (id_currency INTEGER, iso_code TEXT)',
            'CREATE TABLE ps_order_state_lang (id_order_state INTEGER, id_lang INTEGER, name TEXT)',
            'CREATE TABLE ps_order_detail (id_order INTEGER, product_id INTEGER, total_price_tax_incl TEXT)',
            'CREATE TABLE ps_order_slip (id_order INTEGER)',
            'CREATE TABLE ps_product (id_product INTEGER, id_manufacturer INTEGER)',
            'CREATE TABLE ps_manufacturer_details (id_manufacturer INTEGER, commission TEXT)',
            'CREATE TABLE ps_financial_transactions (id INTEGER, order_id INTEGER, currency TEXT, type TEXT, amount REAL, status TEXT)',
            'CREATE TABLE ps_financial_transaction_events (id INTEGER, financial_transaction_id INTEGER, status TEXT, event_type TEXT, occurred_at TEXT)',
            "INSERT INTO ps_currency VALUES (1, 'EUR')",
            "INSERT INTO ps_order_state_lang VALUES (2, 1, 'Pagato')",
            "INSERT INTO ps_orders VALUES (1,1,1,'AAA','2026-05-01 00:00:00',2,1,'Stripe','106.100000','6.100000','0.000000'), (2,2,1,'BBB','2026-06-01 00:00:00',2,1,'Stripe','106.100000','6.100000','0.000000')",
            "INSERT INTO ps_order_detail VALUES (1, 10, '100.000000'), (2, 10, '100.000000')",
            "INSERT INTO ps_product VALUES (10, 20)",
            "INSERT INTO ps_manufacturer_details VALUES (20, '20.00')",
            "INSERT INTO ps_financial_transactions VALUES (1,1,'EUR','provider_fee',-2.00,'pending')",
            "INSERT INTO ps_financial_transaction_events VALUES (1,1,'available','status_changed','2026-06-02')",
        ];
        foreach ($sql as $statement) $this->db->pdo->exec($statement);
        $this->repository = new DzOrderMarginRepository($this->db);
    }

    public function testReportUsesCurrentSellerRateAndLedgerFeeWithinShop()
    {
        $rows = $this->repository->orders([1], 1);
        self::assertCount(1, $rows);
        self::assertSame('2.00', $rows[0]['fee']);
        self::assertSame('Registro', $rows[0]['fee_source']);
        self::assertSame('24.10', $rows[0]['calculated']['margin']);
        $this->db->pdo->exec("UPDATE ps_manufacturer_details SET commission = '30.00'");
        self::assertSame('34.10', $this->repository->orders([1], 1)[0]['calculated']['margin']);
    }

    public function testRefundSuppressesMarginAndManualFeeIsShopScoped()
    {
        $this->db->pdo->exec("INSERT INTO ps_financial_transactions VALUES (2,1,'EUR','refund',-10,'available')");
        self::assertNull($this->repository->orders([1], 1)[0]['calculated']['margin']);
        self::assertFalse($this->repository->saveFee(2, [1], '3.00'));
        self::assertTrue($this->repository->saveFee(1, [1], '3,00'));
        self::assertSame('3.00', $this->db->pdo->query('SELECT fee_override FROM ps_dz_order_margin WHERE id_order = 1')->fetchColumn());
        self::assertSame('3.00', $this->repository->orders([1], 1)[0]['fee']);
        self::assertSame('Manuale', $this->repository->orders([1], 1)[0]['fee_source']);
        self::assertTrue($this->repository->saveFee(1, [1], ''));
        self::assertSame(0, (int) $this->db->pdo->query('SELECT COUNT(*) FROM ps_dz_order_margin')->fetchColumn());
        self::assertSame('2.00', $this->repository->orders([1], 1)[0]['fee']);
    }

    public function testCreditNoteAndReversedFeeRemainUnresolved()
    {
        $this->db->pdo->exec("INSERT INTO ps_financial_transaction_events VALUES (2,1,'reversed','status_changed','2026-06-03')");
        $this->db->pdo->exec('INSERT INTO ps_order_slip VALUES (1)');
        $row = $this->repository->orders([1], 1)[0];
        self::assertNull($row['fee']);
        self::assertNull($row['calculated']['margin']);
        self::assertContains('Rimborso presente: verificare', $row['calculated']['note']);
    }
}
