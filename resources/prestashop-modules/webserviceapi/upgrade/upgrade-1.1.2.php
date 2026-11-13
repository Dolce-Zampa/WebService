<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_2($module)
{
    return $module->installMarketplaceShipping();
}
