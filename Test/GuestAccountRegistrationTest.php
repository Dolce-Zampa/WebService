<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Http\Controller\CustomerController;
use PS\Webservice\Repositories\CustomerRepository;
use PS\Webservice\Service\Auth\AuthService;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\PS\Customer;
use PS\Webservice\Service\PS\Mailer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class GuestAccountRegistrationTest extends TestCase
{
    private mixed $previousFacade;
    private mixed $previousDatabaseManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousDatabaseManager = (new ReflectionProperty(Manager::class, 'instance'))->getValue();
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        (new ReflectionProperty(Manager::class, 'instance'))->setValue(null, $this->previousDatabaseManager);
        parent::tearDown();
    }

    public function testGuestIsPromotedInPlaceWithCognitoIdentityAndOrderReference(): void
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $schema = $db->getConnection()->getSchemaBuilder();
        $schema->create('configuration', function ($t) { $t->string('name'); $t->string('value'); });
        $schema->create('customer', function ($t) {
            $t->increments('id_customer'); $t->string('email'); $t->string('sub')->nullable();
            $t->integer('is_guest')->default(0); $t->integer('deleted')->default(0);
            $t->string('passwd'); $t->string('firstname'); $t->string('lastname');
            $t->string('birthday')->nullable(); $t->integer('newsletter')->default(0);
            $t->string('date_upd')->nullable(); $t->string('uuid')->nullable();
            $t->integer('active')->default(1); $t->integer('id_lang')->default(1);
            $t->string('newsletter_date_add')->nullable(); $t->integer('max_payment_days')->default(0);
            $t->string('secure_key'); $t->integer('id_default_group')->default(1);
        });
        $schema->create('customer_group', function ($t) { $t->integer('id_customer'); $t->integer('id_group'); });
        $schema->create('orders', function ($t) { $t->integer('id_customer'); });
        $db->table('configuration')->insert(['name' => 'PS_LANG_DEFAULT', 'value' => '1']);
        $db->table('customer')->insert(['id_customer' => 7, 'email' => 'guest@example.test', 'is_guest' => 1,
            'passwd' => 'old', 'firstname' => 'Guest', 'lastname' => 'Customer', 'secure_key' => 'original']);
        $db->table('orders')->insert(['id_customer' => 7]);
        $fields = ['email' => 'guest@example.test', 'password' => 'Password1@', 'firstname' => 'Ada',
            'lastname' => 'Rossi', 'newsletter' => false, 'uuid' => 'uuid'];
        $data = $this->createMock(ObjectInterface::class);
        $data->method('__get')->willReturnCallback(static fn($k) => $fields[$k] ?? null);
        $data->method('get')->willReturnCallback(static fn($k, $default = null) => $fields[$k] ?? $default);
        $repo = new CustomerRepository($db);
        $result = $repo->saveNewCustomer($data, 'cognito-sub', true);
        self::assertSame(7, $result->id_customer);
        self::assertSame(0, $result->is_guest);
        self::assertSame('cognito-sub', $result->sub);
        self::assertSame('original', $result->secure_key);
        self::assertTrue(password_verify('Password1@', $result->passwd));
        self::assertSame(7, $db->table('orders')->value('id_customer'));
        self::assertSame(3, $db->table('customer_group')->value('id_group'));
        $this->expectException(RuntimeException::class);
        $repo->saveNewCustomer($data, 'different-sub', true);
    }

    public function testRegistrationRequiresValidOneTimeEmailVerification(): void
    {
        $cache = new CacheRepository(new ArrayStore());
        Facade::setFacadeApplication(['cache' => $cache, 'log' => new \Psr\Log\NullLogger()]);
        $auth = $this->createMock(AuthService::class);
        $auth->expects(self::once())->method('signUp')->willReturn(false);
        $controller = new CustomerController($this->createMock(Customer::class), $auth,
            $this->createMock(CustomerRepository::class), $this->createMock(Mailer::class), $this->createMock(MailjetService::class));
        $payload = ['email' => 'guest@example.test', 'password' => 'Password1@', 'firstname' => 'Ada', 'lastname' => 'Rossi'];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/register')->withParsedBody($payload);
        self::assertSame(400, $controller->register($request, new Response(), [])->getStatusCode());
        $token = str_repeat('a', 64);
        $cache->tags(['registration-verification'])->put('token:' . hash('sha256', $token), 'guest@example.test', 3600);
        $request = $request->withParsedBody(array_merge($payload, ['email' => 'other@example.test', 'verification_token' => $token]));
        self::assertSame(400, $controller->register($request, new Response(), [])->getStatusCode());
        $request = $request->withParsedBody($payload + ['verification_token' => $token]);
        self::assertSame(400, $controller->register($request, new Response(), [])->getStatusCode());
        self::assertSame(400, $controller->register($request, new Response(), [])->getStatusCode());
    }
}
