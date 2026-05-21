<?php
declare(strict_types=1);

namespace PS\Webservice\Facades;

use Mdf\JsonStorage\Service\DbService;

final class JsonDataStorage
{
    public static function coupon(): DbService
    {
        return new DbService('coupons');
    }

    public static function carriers(): DbService
    {
        return new DbService('coupons');
    }

    public static function carts(): DbService
    {
        return new DbService('carts');
    }

    public static function httpRequest(): DbService
    {
        return new DbService('requests');
    }

    public static function productBundles(): DbService
    {
        return new DbService('product_bundles');
    }
}