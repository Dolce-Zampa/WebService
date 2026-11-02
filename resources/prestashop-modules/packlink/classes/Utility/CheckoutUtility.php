<?php
/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
namespace Packlink\PrestaShop\Classes\Utility;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Packlink\PrestaShop\Classes\Entities\CartCarrierDropOffMapping;

class CheckoutUtility
{
    /**
     * Checks whether if drop-off is selected.
     *
     * @param string $cartId
     *
     * @param $carrierId
     *
     * @return bool
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public static function isDropOffSelected($cartId, $carrierId)
    {
        $repository = RepositoryRegistry::getRepository(CartCarrierDropOffMapping::getClassName());

        $query = new \Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter();
        $query->where('cartId', '=', $cartId)
            ->where('carrierReferenceId', '=', $carrierId);

        return $repository->selectOne($query) !== null;
    }
}
