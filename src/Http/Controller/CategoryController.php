<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Category;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryController extends Controller
{
    private Category $categoryService;

    public function __construct(Category $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function categoryListById(Request $request, Response $response, array $argv): Response
    {
        $categoryId = $argv['id_category'];
        $category = $this->categoryService->categoriesList([
            'display' => 'full',
            'filter[id]' => $categoryId
        ]);;

        if (is_null($category)) {
            return response([], 404);
        }

        return response($category->toArray());
    }

    public function categoryList(Request $request, Response $response): Response
    {
        $categories = $this->categoryService->categoriesList([
            'display' => 'full'
        ]);

        return response($categories->toArray());
    }
}
