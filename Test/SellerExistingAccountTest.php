<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Facade;
use malirobot\AwsCognito\Exception\UsernameExistsException;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Http\Controller\Seller\SellerController;
use PS\Webservice\Service\Auth\SignUpService;

final class SellerExistingAccountTest extends TestCase
{
    private mixed $previous;
    private mixed $previousDatabaseManager;

    protected function setUp(): void
    {
        $this->previous = Facade::getFacadeApplication();
        $this->previousDatabaseManager = (new ReflectionProperty(\Illuminate\Database\Capsule\Manager::class, 'instance'))->getValue();
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        (new ReflectionProperty(\Illuminate\Database\Capsule\Manager::class, 'instance'))->setValue(null, $this->previousDatabaseManager);
    }

    private function payload(string $token, string $password): ObjectInterface
    {
        $values = ['auth_token' => $token, 'password' => $password, 'email' => 'seller@example.com', 'is_seller' => true, 'name' => 'Shop'];
        $data = $this->createMock(ObjectInterface::class);
        $data->method('get')->willReturnCallback(static fn ($key, $default = null) => $values[$key] ?? $default);
        $data->method('__get')->willReturnCallback(static fn ($key) => $values[$key] ?? null);
        return $data;
    }

    public function testAuthenticatedSellerDoesNotReplaceCustomerOrPassword(): void
    {
        $cognito = new class {
            public array $updates = [];
            public function decodeAccessToken($token) { return ['sub' => 'verified-sub']; }
            public function updateUserAttributes($email, $attributes) { $this->updates[] = [$email, $attributes]; }
        };
        Facade::setFacadeApplication(['aws-cognito-client' => $cognito, 'log' => new \Psr\Log\NullLogger()]);
        $service = (new ReflectionClass(SignUpService::class))->newInstanceWithoutConstructor();
        $result = $service->signUp($this->payload('verified-access-token', ''));
        self::assertSame('verified-sub', $result['sub']);
        self::assertFalse($result['is_new_user']);
        self::assertSame('seller@example.com', $cognito->updates[0][0]);
    }

    public function testWrongPasswordDoesNotPromoteExistingUser(): void
    {
        $cognito = new class {
            public function setBoolClientSecret() { return $this; }
            public function authenticate($email, $password) { return ['error' => 'Incorrect username or password']; }
        };
        Facade::setFacadeApplication(['aws-cognito-client' => $cognito, 'log' => new \Psr\Log\NullLogger()]);
        $service = $this->getMockBuilder(SignUpService::class)->disableOriginalConstructor()->onlyMethods(['createCognitoUser'])->getMock();
        $service->method('createCognitoUser')->willThrowException(new UsernameExistsException());
        self::assertFalse($service->signUp($this->payload('', 'wrong-password')));
    }

    public function testExistingCustomerCanCompleteSignupWithVerifiedPassword(): void
    {
        $db = new \Illuminate\Database\Capsule\Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->getConnection()->getSchemaBuilder()->create('customer', function ($table): void {
            $table->integer('id_customer');
            $table->string('email');
            $table->string('passwd');
        });
        $db->table('customer')->insert(['id_customer' => 1, 'email' => 'seller@example.com', 'passwd' => 'unchanged']);
        $repository = new class($db) {
            public function __construct(private $db) {}
            public function getDbInstance() { return $this->db; }
        };
        $cognito = new class {
            public array $updates = [];
            public function setBoolClientSecret() { return $this; }
            public function authenticate($email, $password) { return ['IdToken' => 'id-token']; }
            public function decodeAccessToken($token) { return ['sub' => 'verified-sub', 'token_use' => 'id', 'exp' => time() + 60, 'email_verified' => true, 'email' => 'seller@example.com']; }
            public function updateUserAttributes($email, $attributes) { $this->updates[] = [$email, $attributes]; }
        };
        Facade::setFacadeApplication(['aws-cognito-client' => $cognito, 'public-repositories' => $repository, 'log' => new \Psr\Log\NullLogger()]);
        $service = $this->getMockBuilder(SignUpService::class)->disableOriginalConstructor()->onlyMethods(['createCognitoUser'])->getMock();
        $service->method('createCognitoUser')->willThrowException(new UsernameExistsException());
        $result = $service->signUp($this->payload('', 'correct-password'));
        self::assertSame('verified-sub', $result['sub']);
        self::assertSame('unchanged', $db->table('customer')->where('id_customer', 1)->value('passwd'));
        self::assertCount(1, $cognito->updates);
    }

    public function testConversionRequiresMatchingVerifiedIdentity(): void
    {
        $previousEnv = $_ENV['APP_DISABLE_CACHE'] ?? null;
        $previousServer = $_SERVER['APP_DISABLE_CACHE'] ?? null;
        $_SERVER['APP_DISABLE_CACHE'] = $_ENV['APP_DISABLE_CACHE'] = 'false';
        $cache = new Repository(new ArrayStore());
        $cache->tags([])->put(sha1('verified-sub'), 'id-token');
        $cognito = new class {
            public bool $verified = true;
            public function decodeAccessToken($token) {
                return $token === 'access-token'
                    ? ['sub' => 'verified-sub', 'token_use' => 'access', 'exp' => time() + 60]
                    : ['sub' => 'verified-sub', 'token_use' => 'id', 'exp' => time() + 60, 'email_verified' => $this->verified, 'email' => 'seller@example.com'];
            }
        };
        Facade::setFacadeApplication(['aws-cognito-client' => $cognito, 'cache' => $cache]);
        $controller = (new ReflectionClass(SellerController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SellerController::class, 'extractAuthenticatedIdentity');
        self::assertSame('seller@example.com', $method->invoke($controller, 'access-token')['email']);
        $cognito->verified = false;
        try {
            $method->invoke($controller, 'access-token');
            self::fail('Unverified email must be rejected');
        } catch (RuntimeException $e) {
            self::assertSame('Invalid authenticated identity', $e->getMessage());
        } finally {
            if ($previousEnv === null) unset($_ENV['APP_DISABLE_CACHE']); else $_ENV['APP_DISABLE_CACHE'] = $previousEnv;
            if ($previousServer === null) unset($_SERVER['APP_DISABLE_CACHE']); else $_SERVER['APP_DISABLE_CACHE'] = $previousServer;
        }
    }
}
