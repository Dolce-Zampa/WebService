<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;

final class EmployeeFinancialAuthServiceTest extends TestCase
{
    private DB $db;
    private EmployeeFinancialAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new DB();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->db->schema()->create('employee', function ($table): void {
            $table->increments('id_employee');
            $table->unsignedInteger('id_profile');
            $table->string('email', 191)->unique();
            $table->string('passwd', 255);
            $table->boolean('active');
        });
        $this->service = new EmployeeFinancialAuthService($this->db, str_repeat('s', 32), 3600, [1]);
    }

    public function test_active_allowed_employee_receives_and_revalidates_a_financial_only_session(): void
    {
        $this->employee(1, 1, 'finance@example.test', 'correct-password', true);

        $session = $this->service->login(['email' => 'finance@example.test', 'password' => 'correct-password']);

        self::assertSame('Bearer', $session['token_type']);
        self::assertSame(3600, $session['expires_in']);
        self::assertSame(['employee_id' => 1, 'profile_id' => 1], $this->service->authenticate($session['access_token']));
    }

    public function test_disabling_an_employee_revokes_an_already_issued_session(): void
    {
        $this->employee(1, 1, 'finance@example.test', 'correct-password', true);
        $token = $this->service->login(['email' => 'finance@example.test', 'password' => 'correct-password'])['access_token'];
        $this->db->table('employee')->where('id_employee', 1)->update(['active' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->authenticate($token);
    }

    public function test_rejects_wrong_password_and_employee_profiles_without_financial_access(): void
    {
        $this->employee(1, 1, 'finance@example.test', 'correct-password', true);
        $this->employee(2, 2, 'operator@example.test', 'other-password', true);

        foreach ([
            ['email' => 'finance@example.test', 'password' => 'wrong-password'],
            ['email' => 'operator@example.test', 'password' => 'other-password'],
        ] as $credentials) {
            try {
                $this->service->login($credentials);
                self::fail('Una sessione finanziaria deve essere rilasciata solo ai dipendenti autorizzati.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function employee(int $id, int $profile, string $email, string $password, bool $active): void
    {
        $this->db->table('employee')->insert([
            'id_employee' => $id, 'id_profile' => $profile, 'email' => $email,
            'passwd' => password_hash($password, PASSWORD_BCRYPT), 'active' => $active,
        ]);
    }
}
