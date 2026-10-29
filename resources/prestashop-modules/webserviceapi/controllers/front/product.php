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
        $promotionProductId = (int) Tools::getValue('promotion_product_id');
        if ($promotionProductId > 0) {
            // Match the defaults used by the PrestaShop webservice price calculation.
            $datesForAttribute = function ($attributeId) use ($promotionProductId) {
                $specificPrice = SpecificPrice::getSpecificPrice(
                    $promotionProductId, (int) Context::getContext()->shop->id,
                    (int) Currency::getDefaultCurrencyId(), (int) Configuration::get('PS_COUNTRY_DEFAULT'),
                    (int) Configuration::get('PS_CUSTOMER_GROUP'), 1, (int) $attributeId, 0, 0
                );
                $dates = array('start' => null, 'end' => null);
                if (!empty($specificPrice)) {
                    foreach (array('from' => 'start', 'to' => 'end') as $source => $target) {
                        $value = $specificPrice[$source] ?? '';
                        if ($value && $value !== '0000-00-00 00:00:00') {
                            $dates[$target] = (new DateTimeImmutable($value, new DateTimeZone(Configuration::get('PS_TIMEZONE') ?: 'Europe/Rome')))->format(DateTimeInterface::ATOM);
                        }
                    }
                }
                return $dates;
            };
            $dates = $datesForAttribute(0);
            $dates['combinations'] = array();
            $attributes = Db::getInstance()->executeS('SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . $promotionProductId);
            if ($attributes === false) {
                throw new PrestaShopException('Unable to retrieve product combinations.');
            }
            foreach ($attributes as $attribute) {
                $id = (int) $attribute['id_product_attribute'];
                $dates['combinations'][$id] = $datesForAttribute($id);
            }
            return $dates;
        }

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

        return array_merge(['id_product' => $id_product], $this->module->getProductConfigurator($id_product));
    }

}