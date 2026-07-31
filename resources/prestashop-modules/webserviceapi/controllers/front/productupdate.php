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
 *                  meta_description, active
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

        return array(
            'id'      => $productId,
            'updated' => true,
            'active'  => (int) $product->active,
        );
    }
}
