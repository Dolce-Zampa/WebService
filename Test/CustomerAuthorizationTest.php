<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\CustomerController;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\Auth\AuthService;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\PS\Customer;
use PS\Webservice\Service\PS\Mailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CustomerAuthorizationTest extends TestCase
{
    public function test_get_account_returns_403_for_another_customer(): void
    {
        $customerService = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAccount'])
            ->getMock();
        $customerService->expects($this->never())->method('getAccount');

        $repository = $this->getMockBuilder(PrestashopRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserIdFromSub'])
            ->getMock();
        $repository->method('findUserIdFromSub')
            ->with('customer-sub')
            ->willReturn(5);

        $controller = new CustomerController(
            $customerService,
            $this->createMock(AuthService::class),
            $repository,
            $this->createMock(Mailer::class),
            $this->createMock(MailjetService::class)
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getAccount($request, $response, ['customerId' => 9]);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_get_account_returns_service_response_for_authenticated_customer(): void
    {
        $serviceResponse = $this->createMock(HttpServiceInterface::class);
        $serviceResponse->method('failed')->willReturn(false);
        $serviceResponse->method('toArray')->willReturn([
            'data' => [
                'customer' => ['id_customer' => 5],
                'addresses' => [],
            ],
        ]);

        $customerService = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAccount'])
            ->getMock();
        $customerService->expects($this->once())
            ->method('getAccount')
            ->with(5)
            ->willReturn($serviceResponse);

        $repository = $this->getMockBuilder(PrestashopRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserIdFromSub'])
            ->getMock();
        $repository->method('findUserIdFromSub')
            ->with('customer-sub')
            ->willReturn(5);

        $controller = new CustomerController(
            $customerService,
            $this->createMock(AuthService::class),
            $repository,
            $this->createMock(Mailer::class),
            $this->createMock(MailjetService::class)
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getAccount($request, $response, ['customerId' => 5]);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame(5, $body['data']['customer']['id_customer']);
    }
}
