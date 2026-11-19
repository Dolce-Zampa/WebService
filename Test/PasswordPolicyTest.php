<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\ResetCustomerPasswordCommand;
use PS\Webservice\Http\Controller\CustomerController;

final class PasswordPolicyTest extends TestCase
{
    public function testPasswordResetAcceptsOnlyRequestedSpecialCharacters(): void
    {
        $patterns = [
            (new ReflectionClass(CustomerController::class))->getReflectionConstant('PASSWORD_VALIDATION')->getValue(),
            (new ReflectionClass(ResetCustomerPasswordCommand::class))->getReflectionConstant('PASSWORD_PATTERN')->getValue(),
        ];
        foreach ($patterns as $pattern) {
            foreach (['Password1@', 'Password1#', 'Password1@#'] as $password) {
                self::assertSame(1, preg_match($pattern, $password));
            }
            foreach (['Password1!', 'Password1@!', 'Password1', 'password1#'] as $password) {
                self::assertSame(0, preg_match($pattern, $password));
            }
        }
    }
}
