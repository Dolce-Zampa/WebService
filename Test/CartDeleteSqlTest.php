<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartDeleteSqlTest extends TestCase
{
    public function test_delete_cart_does_not_add_a_limit_to_the_get_value_query(): void
    {
        $controller = file_get_contents(
            __DIR__ . '/../resources/prestashop-modules/webserviceapi/controllers/front/cart.php'
        );

        $this->assertNotFalse($controller);

        $start = strpos($controller, 'protected function handleDeleteRequest()');
        $end = strpos($controller, 'protected function handleGetRequest()', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $deleteHandler = substr($controller, $start, $end - $start);

        $this->assertDoesNotMatchRegularExpression(
            '/WHERE `id_cart` = .+? LIMIT 1/s',
            $deleteHandler
        );
    }
}
