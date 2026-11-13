<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';
require_once dirname(__FILE__) . '/../../classes/MlabMarketplaceShipping.php';

class webserviceapishippingpolicyModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('GET'));
        MlabMarketplaceShipping::ensureReady($this->module, $this->context);
        return array('free_shipping_threshold' => MlabMarketplaceShipping::threshold());
    }
}
