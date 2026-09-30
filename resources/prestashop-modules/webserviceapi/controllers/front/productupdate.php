<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

/**
 * Endpoint: POST /api/products/update
 *
 * Accepts a JSON body with the product ID and the fields to update.
 * The product is saved but kept inactive (active = 0) unless the caller
 * explicitly passes "active": 1.
 *
 * Required fields: id (int)
 * Optional fields: name, description, description_short, meta_title,
 *                  meta_description, active, configurator_active, configurator_json
 */
class webserviceapiproductupdateModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));

        $payload   = $this->getJsonPayload();
        $productId = (int) MlabFactoryApiHelper::getValue($payload, 'id', 0);

        if ($productId <= 0) {
            throw new MlabFactoryApiException('Field "id" is required and must be a positive integer.', 400);
        }

        $product = new Product($productId, true);
        if (!Validate::isLoadedObject($product)) {
            throw new MlabFactoryApiException('Product not found.', 404, array('id' => $productId));
        }

        $configurator = $this->module->getProductConfigurator($productId);
        if (array_key_exists('configurator_active', $payload)) {
            if (!in_array($payload['configurator_active'], array(true, false, 0, 1), true)) {
                throw new MlabFactoryApiException('configurator_active must be a boolean.', 400);
            }
            $configurator['configurator_active'] = (bool) $payload['configurator_active'];
        }
        if (array_key_exists('configurator_json', $payload)) {
            try {
                $this->module->validateConfiguratorJson($payload['configurator_json']);
            } catch (InvalidArgumentException $exception) {
                throw new MlabFactoryApiException($exception->getMessage(), 400);
            }
            $configurator['configurator_json'] = $payload['configurator_json'];
        }

        $langId = (int) Configuration::get('PS_LANG_DEFAULT');

        // Apply only the fields provided in the payload
        if (array_key_exists('name', $payload) && $payload['name'] !== '' && $payload['name'] !== null) {
            $product->name[$langId] = (string) $payload['name'];
        }
        if (array_key_exists('description', $payload)) {
            $product->description[$langId] = (string) $payload['description'];
        }
        if (array_key_exists('description_short', $payload)) {
            $product->description_short[$langId] = (string) $payload['description_short'];
        }
        if (array_key_exists('meta_title', $payload)) {
            $product->meta_title[$langId] = (string) $payload['meta_title'];
        }
        if (array_key_exists('meta_description', $payload)) {
            $product->meta_description[$langId] = (string) $payload['meta_description'];
        }
        if (array_key_exists('url', $payload)) {
            $product->link_rewrite[$langId] = (string) $payload['url'];
        }

        // Default to inactive (unpublished); the caller may override with active=1
        $product->active = array_key_exists('active', $payload) ? (int) $payload['active'] : 0;

        if (!$product->update()) {
            throw new MlabFactoryApiException('Failed to update product.', 500, array('id' => $productId));
        }

        if (array_key_exists('configurator_active', $payload) || array_key_exists('configurator_json', $payload)) {
            $this->module->saveProductConfigurator($productId, $configurator['configurator_active'], $configurator['configurator_json']);
            // Product::update() sends the existing cache webhook before these values are saved.
            $this->module->clearProductConfiguratorCache($product);
        }

        return array(
            'id'      => $productId,
            'updated' => true,
            'configurator_active' => $configurator['configurator_active'],
            'configurator_json' => $configurator['configurator_json'],
            'active'  => (int) $product->active,
        );
    }
}
