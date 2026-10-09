<?php
declare(strict_types=1);

use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Category;
use PHPUnit\Framework\TestCase;

final class CategoryServiceTest extends TestCase
{
    public function test_categories_list_returns_full_normalized_categories(): void
    {
        $_ENV['PS_BASE_URL'] = 'shop.example.com';

        $httpService = $this->createMock(HttpServiceInterface::class);
        $httpService->expects($this->once())
            ->method('setUrl')
            ->with('/categories?display=full');
        $httpService->expects($this->once())
            ->method('invoke')
            ->with('GET')
            ->willReturn($httpService);
        $httpService->expects($this->once())
            ->method('failed')
            ->willReturn(false);
        $httpService->expects($this->once())
            ->method('toArray')
            ->willReturn([
                'categories' => [
                    [
                        'id' => '12',
                        'id_parent' => '2',
                        'name' => [
                            ['id' => '1', 'value' => 'Cani'],
                        ],
                        'link_rewrite' => [
                            ['id' => '1', 'value' => 'cani'],
                        ],
                        'description' => [
                            ['id' => '1', 'value' => '<p>Tutto per i cani</p>'],
                        ],
                        'meta_title' => [
                            ['id' => '1', 'value' => 'Categoria Cani'],
                        ],
                        'meta_description' => [
                            ['id' => '1', 'value' => 'Acquista prodotti per cani'],
                        ],
                        'meta_keywords' => [
                            ['id' => '1', 'value' => 'cani,pet'],
                        ],
                        'active' => '1',
                        'position' => '4',
                        'is_root_category' => '0',
                        'date_add' => '2024-01-01 00:00:00',
                        'date_upd' => '2024-01-02 00:00:00',
                        'associations' => [
                            'products' => [
                                ['id' => '99'],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new Category($httpService);
        $category = $service->categoriesList()->first();

        $this->assertSame([
            'id' => 12,
            'parent_id' => 2,
            'name' => 'Cani',
            'url' => 'https://shop.example.com/12-cani',
            'slug' => 'cani',
            'short_description' => 'Promo cani',
            'description' => '<p>Tutto per i cani</p>',
            'additional_description' => 'Promo cani',
            'title' => 'Categoria Cani',
            'meta_title' => 'Categoria Cani',
            'meta_description' => 'Acquista prodotti per cani',
            'meta_keywords' => 'cani,pet',
            'active' => true,
            'position' => 4,
            'is_root_category' => false,
            'date_add' => '2024-01-01 00:00:00',
            'date_upd' => '2024-01-02 00:00:00',
            'associations' => [
                'products' => [
                    ['id' => '99'],
                ],
            ],
        ], $category);
    }
}
