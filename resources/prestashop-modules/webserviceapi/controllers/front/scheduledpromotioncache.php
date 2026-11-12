<?php
require_once __DIR__ . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';
require_once __DIR__ . '/../../classes/ScheduledPromotionCacheScan.php';

class webserviceapischeduledpromotioncacheModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));
        try {
            return array('products_processed' => ScheduledPromotionCacheScan::run());
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('[webserviceapi] Promotion cache scan failed: ' . $exception->getMessage(), 3);
            throw new MlabFactoryApiException('Promotion cache scan failed.', 500);
        }
    }
}
