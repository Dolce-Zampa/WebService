<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapiproductModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'POST', 'PUT'));

        if ($method === 'GET') {
            return $this->handleGetRequest();
        }

        return response(['error' => 'Method not allowed'], 405);
    }


    protected function handleGetRequest()
    {
        $productSlug = Tools::getValue('by_slug');
        
        if (empty($productSlug)) {
            return response(['error' => 'Product slug is required'], 400);
        }
        
        $id_product = (int)Db::getInstance()->getValue(
            'SELECT id_product FROM ' . _DB_PREFIX_ . 'product_lang 
            WHERE link_rewrite = "' . pSQL($productSlug) . '"'
        );

        if (!$id_product) {
            return throw new MlabFactoryApiException('Product not found', 404);
        }

        return ['id_product' => $id_product];
    }

}