<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PrestaShopCustomerLoginTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSuccessfulLoginRecordsLastLoginOnly(): void
    {
        require __DIR__ . '/stubs/customer-login-runtime.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $result = (new TestLoginController('correct'))->run();
        self::assertSame('Login successful.', $result['message']);
        self::assertCount(1, Db::$updates);
        [$table, $values, $where] = Db::$updates[0];
        self::assertSame('customer', $table);
        self::assertSame('id_customer = 7', $where);
        self::assertSame(['last_login'], array_keys($values));
        self::assertNotFalse(DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $values['last_login']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInvalidCredentialsDoNotRecordLogin(): void
    {
        require __DIR__ . '/stubs/customer-login-runtime.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            (new TestLoginController('wrong'))->run();
            self::fail('Expected invalid credentials');
        } catch (MlabFactoryApiException $exception) {
            self::assertSame(401, $exception->getStatusCode());
        }
        self::assertSame([], Db::$updates);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDatabaseFailureDoesNotReportSuccessfulLogin(): void
    {
        require __DIR__ . '/stubs/customer-login-runtime.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Db::$updateResult = false;
        $this->expectException(MlabFactoryApiException::class);
        $this->expectExceptionMessage('Unable to record customer login.');
        (new TestLoginController('correct'))->run();
    }
}
