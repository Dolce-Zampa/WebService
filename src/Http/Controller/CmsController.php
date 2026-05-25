<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Cms;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CmsController extends Controller
{
    private Cms $cmsService;

    public function __construct(Cms $cmsService)
    {
        $this->cmsService = $cmsService;
    }

    public function cmsList(Request $request, Response $response): Response
    {
        $cms = $this->cmsService->cmsList();

        return response($cms->toArray()['content_management_system']);
    }

    public function cmsDetail(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $cms = $this->cmsService->cmsDetail($id);

        return response($cms->toArray());
    }

    public function redirectToPrestashop(Request $request, Response $response, array $args): Response
    {
        $uri = $request->getUri()->getPath();
        $queryString = $request->getUri()->getQuery();
        $method = strtoupper($request->getMethod());
        $fullUrl = $uri . ($queryString ? '?' . $queryString : '');

        $payload = (array) ($request->getParsedBody() ?? []);
        if (empty($payload)) {
            $rawBody = (string) $request->getBody();
            $decodedBody = json_decode($rawBody, true);
            if (is_array($decodedBody)) {
                $payload = $decodedBody;
            }
        }
        
        // Costruisci l'URL completo per il reindirizzamento
        $response = $this->cmsService->toPrestashop($fullUrl, $method, $payload);
        return response($response->toArray());
    }
}
