<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Brand;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BrandController extends Controller
{
    private Brand $brandService;

    public function __construct(Brand $brandService)
    {
        $this->brandService = $brandService;
    }

    public function brandList(Request $request, Response $response, array $argv): Response
    {
        $id = $argv['id'];
        $category = $this->brandService->brandsList([
            'display' => 'full',
            'filter[id]' => $id
        ]);;

        if (is_null($category)) {
            return response([], 404);
        }

        return response($category->toArray());
    }

    public function categoryList(Request $request, Response $response): Response
    {
        $categories = $this->brandService->categoriesList([ //FIXME: this should be in category controller
            'display' => 'full'
        ]);

        return response($categories->toArray());
    }
}
