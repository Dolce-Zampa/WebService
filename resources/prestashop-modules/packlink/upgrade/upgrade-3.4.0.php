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
use Packlink\PrestaShop\Classes\Utility\PacklinkInstaller;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_3_4_0($module)
{
    \Packlink\PrestaShop\Classes\Bootstrap::init();
    $previousShopContext = \Shop::getContext();
    \Shop::setContext(\Shop::CONTEXT_ALL);

    $installer = new PacklinkInstaller($module);
    $installer->addController('CashOnDelivery');

    \Shop::setContext($previousShopContext);

    return true;
}
