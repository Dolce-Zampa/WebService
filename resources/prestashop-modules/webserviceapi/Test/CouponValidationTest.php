<?php
use PHPUnit\Framework\TestCase;

// Core doubles: execute the shared validator without a running PrestaShop installation.
class CartRule
{
    const FILTER_ACTION_ALL = 1;
    public $id = 7;
    public $active = true;
    public $quantity = 1;
    public $date_from = '2020-01-01';
    public $date_to = '2100-01-01';
    public $result = null;
    public $arguments;
    public function checkValidity($context, $alreadyInCart, $displayError)
    {
        $this->arguments = [$context, $alreadyInCart, $displayError];
        return $this->result;
    }
}
class Cart
{
    public $id_customer = 3;
    public $id_currency = 2;
    public $id_lang = 4;
    public $id_address_delivery = 5;
    public $rules = [];
    public function getCartRules($filter, $autoAdd) { return $this->rules; }
}
class Customer { public function __construct(public $id) {} }
class Currency { public function __construct(public $id) {} }
class Language { public function __construct(public $id) {} }
class Country { public function __construct(public $id) {} }
class Address { public static function getCountryAndState($id) { return ['id_country' => 8]; } }
class Configuration { public static function get($key) { return 1; } }
require_once __DIR__ . '/../classes/MlabFactoryCoupon.php';

final class CouponValidationTest extends TestCase
{
    public function test_valid_coupon_uses_cart_context_and_null_success(): void
    {
        $rule = new CartRule(); $cart = new Cart(); $context = new stdClass();
        $this->assertNull(MlabFactoryCoupon::error($rule, $cart, $context));
        $this->assertSame([$context, false, true], $rule->arguments);
        $this->assertSame($cart, $context->cart);
        $this->assertSame(2, $context->currency->id);
        $this->assertSame(4, $context->language->id);
        $this->assertSame(8, $context->country->id);
    }

    public function test_core_rejection_is_preserved(): void
    {
        $rule = new CartRule(); $rule->result = 'Minimum spend not reached.';
        $this->assertSame($rule->result, MlabFactoryCoupon::error($rule, new Cart(), new stdClass()));
        $rule->result = false;
        $this->assertSame('Coupon is not valid.', MlabFactoryCoupon::error($rule, new Cart(), new stdClass()));
    }

    public function test_existing_coupon_is_validated_as_already_in_cart(): void
    {
        $rule = new CartRule(); $cart = new Cart(); $cart->rules = [['id_cart_rule' => 7]];
        $context = new stdClass();
        $this->assertNull(MlabFactoryCoupon::error($rule, $cart, $context));
        $this->assertSame([$context, true, true], $rule->arguments);
    }

    public static function invalidRules(): array
    {
        return [['active', false], ['quantity', 0], ['date_from', '2100-01-01'], ['date_to', '2020-01-01']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRules')]
    public function test_expiry_and_availability_are_checked_even_when_already_in_cart(string $field, mixed $value): void
    {
        $rule = new CartRule(); $rule->$field = $value;
        $cart = new Cart(); $cart->rules = [['id_cart_rule' => 7]];
        $this->assertNotNull(MlabFactoryCoupon::error($rule, $cart, new stdClass()));
        $this->assertNull($rule->arguments);
    }
}
