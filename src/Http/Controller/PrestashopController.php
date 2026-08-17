<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\PS\PsModule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestashopController
{
    private PsModule $service;
    private MailjetService $mailjetService;
    public function __construct(PsModule $prestashopService, MailjetService $mailjetService)
    {
        $this->service = $prestashopService;
        $this->mailjetService = $mailjetService;
    }

    public function healthCheck(Request $request, Response $response): Response
    {
        $isConnected = $this->service->checkConnection();
        $status = $isConnected ? 'ok' : 'error';
        $responseData = ['status' => $status];

        return response($responseData, 200);
    }

    public function welcomeCoupon(Request $request, Response $response): Response
    {
        $payload = (array) ($request->getParsedBody() ?? []);
        if (empty($payload) || !isset($payload['email']) || !isset($payload['privacy_accepted']) || !$payload['privacy_accepted'] || !isset($payload['source'])) {
            throw new \InvalidArgumentException('Payload is required');
        }

        $response = $this->service->welcomeCoupon($payload);

        Log::debug('PrestashopController: welcomeCoupon response', ['response' => $response->toArray()]);

        try {
            $this->mailjetService->createNewContact($payload['email']);
        } catch (\Exception $e) {
            Log::creitical('Failed to create new contact in Mailjet', [
                'email' => $payload['email'],
                'error' => $e->getMessage(),
            ]);
        }

        return response($response->toArray(), 200);
    }

}