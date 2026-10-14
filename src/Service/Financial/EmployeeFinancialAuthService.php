<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use Illuminate\Database\Capsule\Manager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticates active PrestaShop Back Office employees for the financial UI.
 *
 * This intentionally does not reuse customer Cognito tokens. A short-lived,
 * HMAC-signed session is accepted exclusively by the financial routes and the
 * employee is rechecked on every request, so disabling an employee or changing
 * their profile revokes access immediately.
 */
final class EmployeeFinancialAuthService
{
    private const ISSUER = 'dolcezampa-financial-dashboard';
    private const AUDIENCE = 'financial-dashboard';
    private const MAX_TOKEN_LENGTH = 4096;

    /** @var list<int> */
    private array $allowedProfileIds;
    private string $secret;
    private int $ttlSeconds;

    /** @param list<int>|null $allowedProfileIds */
    public function __construct(
        private readonly Manager $db,
        ?string $sessionSecret = null,
        ?int $ttlSeconds = null,
        ?array $allowedProfileIds = null,
    ) {
        $this->secret = trim($sessionSecret ?? (string) env('FINANCIAL_DASHBOARD_SESSION_SECRET', ''));
        $this->ttlSeconds = $ttlSeconds ?? $this->configuredTtl();
        if ($this->ttlSeconds < 300 || $this->ttlSeconds > 43200) {
            throw new RuntimeException('Financial employee session TTL must be between 5 minutes and 12 hours.');
        }
        $this->allowedProfileIds = $allowedProfileIds ?? $this->configuredProfileIds();
        if ($this->allowedProfileIds === []) {
            throw new RuntimeException('At least one PrestaShop employee profile must be allowed.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function login(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = $input['password'] ?? null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !is_string($password) || $password === '' || strlen($password) > 4096) {
            throw new InvalidArgumentException('Email and password are required.');
        }

        $employee = $this->db->table('employee')
            ->select(['id_employee', 'id_profile', 'passwd', 'active'])
            ->where('email', $email)
            ->where('active', 1)
            ->first();

        if ($employee === null || !is_string($employee->passwd) || !password_verify($password, $employee->passwd)) {
            throw new InvalidArgumentException('Invalid employee credentials.');
        }

        $employeeId = (int) $employee->id_employee;
        $profileId = (int) $employee->id_profile;
        if ($employeeId < 1 || !$this->profileIsAllowed($profileId)) {
            throw new InvalidArgumentException('This employee is not authorized for the financial dashboard.');
        }

        $now = time();
        return [
            'access_token' => $this->issue($employeeId, $profileId, $now),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttlSeconds,
        ];
    }

    /** @return array{employee_id: int, profile_id: int} */
    public function authenticate(string $token): array
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->json($this->decode($encodedHeader));
        $payload = $this->json($this->decode($encodedPayload));
        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        $expected = $this->encode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->signingSecret(), true));
        if (!hash_equals($expected, $encodedSignature)) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }

        $now = time();
        if (($payload['iss'] ?? null) !== self::ISSUER || ($payload['aud'] ?? null) !== self::AUDIENCE
            || ($payload['kind'] ?? null) !== 'prestashop_employee_financial'
            || !is_int($payload['exp'] ?? null) || !is_int($payload['iat'] ?? null)
            || $payload['exp'] <= $now || $payload['iat'] > $now + 60) {
            throw new InvalidArgumentException('Financial employee session has expired or is invalid.');
        }

        $employeeId = $this->positiveInt($payload['employee_id'] ?? null);
        $profileId = $this->positiveInt($payload['profile_id'] ?? null);
        if ($employeeId === null || $profileId === null) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        $employee = $this->db->table('employee')
            ->select(['id_employee', 'id_profile', 'active'])
            ->where('id_employee', $employeeId)
            ->where('active', 1)
            ->first();
        if ($employee === null || (int) $employee->id_profile !== $profileId || !$this->profileIsAllowed($profileId)) {
            throw new InvalidArgumentException('This employee is no longer authorized for the financial dashboard.');
        }

        return ['employee_id' => $employeeId, 'profile_id' => $profileId];
    }

    private function issue(int $employeeId, int $profileId, int $now): string
    {
        $header = $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->encode(json_encode([
            'iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'kind' => 'prestashop_employee_financial',
            'employee_id' => $employeeId, 'profile_id' => $profileId, 'iat' => $now, 'exp' => $now + $this->ttlSeconds,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', $header . '.' . $payload, $this->signingSecret(), true));
        return $header . '.' . $payload . '.' . $signature;
    }

    private function configuredTtl(): int
    {
        $value = env('FINANCIAL_DASHBOARD_SESSION_TTL', '28800');
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Invalid financial employee session TTL configuration.');
        }
        return (int) $value;
    }

    private function signingSecret(): string
    {
        if (strlen($this->secret) < 32) {
            throw new RuntimeException('Financial employee session signing is not configured.');
        }
        return $this->secret;
    }

    /** @return list<int> */
    private function configuredProfileIds(): array
    {
        $configured = (string) env('FINANCIAL_EMPLOYEE_PROFILE_IDS', '1');
        $profiles = [];
        foreach (explode(',', $configured) as $value) {
            $id = $this->positiveInt(trim($value));
            if ($id === null) {
                throw new RuntimeException('Invalid financial employee profile configuration.');
            }
            $profiles[$id] = $id;
        }
        return array_values($profiles);
    }

    private function profileIsAllowed(int $profileId): bool
    {
        return in_array($profileId, $this->allowedProfileIds, true);
    }

    /** @return array<string, mixed> */
    private function json(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Invalid financial employee session.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        return $decoded;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid financial employee session.');
        }
        return $decoded;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }
        return (int) $value;
    }
}
