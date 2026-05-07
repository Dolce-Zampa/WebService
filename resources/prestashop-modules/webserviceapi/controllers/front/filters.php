<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapifiltersModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET'));

        $idCategory = (int) Tools::getValue('id_category');

        if ($idCategory <= 0) {
            throw new MlabFactoryApiException('id_category parameter is required and must be greater than 0.', 422);
        }

        // Check if category exists
        $category = new Category($idCategory, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($category)) {
            throw new MlabFactoryApiException('Category not found.', 404, array('id_category' => $idCategory));
        }

        // Get available filters for this category
        $filters = $this->getAvailableFilters($idCategory);

        return array(
            'message' => 'Available filters retrieved successfully.',
            'id_category' => $idCategory,
            'category_name' => $category->name,
            'filters' => $filters
        );
    }

    /**
     * Get available filters for a category
     */
    protected function getAvailableFilters($idCategory)
    {
        $filters = array();
        
        // 1. Get Product Features (Caratteristiche)
        $features = $this->getCategoryFeatures($idCategory);
        if (!empty($features)) {
            $filters['features'] = $features;
        }

        // 2. Get Product Attributes (Varianti: colore, taglia, etc)
        $attributes = $this->getCategoryAttributes($idCategory);
        if (!empty($attributes)) {
            $filters['attributes'] = $attributes;
        }

        // 3. Get Price Range
        $priceRange = $this->getCategoryPriceRange($idCategory);
        if (!empty($priceRange)) {
            $filters['price_range'] = $priceRange;
        }

        // 4. Get Manufacturers/Brands
        $manufacturers = $this->getCategoryManufacturers($idCategory);
        if (!empty($manufacturers)) {
            $filters['manufacturers'] = $manufacturers;
        }

        return $filters;
    }

    /**
     * Get product features available in this category
     */
    protected function getCategoryFeatures($idCategory)
    {
        $idLang = (int) $this->context->language->id;
        
        $sql = 'SELECT DISTINCT f.id_feature, fl.name as feature_name,
                fv.id_feature_value, fvl.value as feature_value
                FROM ' . _DB_PREFIX_ . 'feature f
                INNER JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (f.id_feature = fl.id_feature AND fl.id_lang = ' . $idLang . ')
                INNER JOIN ' . _DB_PREFIX_ . 'feature_product fp ON f.id_feature = fp.id_feature
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value fv ON fp.id_feature_value = fv.id_feature_value
                INNER JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON (fv.id_feature_value = fvl.id_feature_value AND fvl.id_lang = ' . $idLang . ')
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON fp.id_product = p.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                WHERE cp.id_category = ' . (int) $idCategory . '
                AND p.active = 1
                ORDER BY fl.name, fvl.value';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return array();
        }

        // Group by feature
        $features = array();
        foreach ($results as $row) {
            $idFeature = (int) $row['id_feature'];
            
            if (!isset($features[$idFeature])) {
                $features[$idFeature] = array(
                    'id_feature' => $idFeature,
                    'name' => $row['feature_name'],
                    'type' => 'feature',
                    'values' => array()
                );
            }

            $features[$idFeature]['values'][] = array(
                'id_feature_value' => (int) $row['id_feature_value'],
                'value' => $row['feature_value']
            );
        }

        return array_values($features);
    }

    /**
     * Get product attributes (variants) available in this category
     */
    protected function getCategoryAttributes($idCategory)
    {
        $idLang = (int) $this->context->language->id;
        
        $sql = 'SELECT DISTINCT ag.id_attribute_group, agl.name as attribute_group_name,
                a.id_attribute, al.name as attribute_name, ag.group_type
                FROM ' . _DB_PREFIX_ . 'attribute_group ag
                INNER JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON (ag.id_attribute_group = agl.id_attribute_group AND agl.id_lang = ' . $idLang . ')
                INNER JOIN ' . _DB_PREFIX_ . 'attribute a ON ag.id_attribute_group = a.id_attribute_group
                INNER JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON (a.id_attribute = al.id_attribute AND al.id_lang = ' . $idLang . ')
                INNER JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON a.id_attribute = pac.id_attribute
                INNER JOIN ' . _DB_PREFIX_ . 'product_attribute pa ON pac.id_product_attribute = pa.id_product_attribute
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON pa.id_product = p.id_product
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                WHERE cp.id_category = ' . (int) $idCategory . '
                AND p.active = 1
                ORDER BY agl.name, al.name';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return array();
        }

        // Group by attribute group
        $attributes = array();
        foreach ($results as $row) {
            $idAttributeGroup = (int) $row['id_attribute_group'];
            
            if (!isset($attributes[$idAttributeGroup])) {
                $attributes[$idAttributeGroup] = array(
                    'id_attribute_group' => $idAttributeGroup,
                    'name' => $row['attribute_group_name'],
                    'type' => 'attribute',
                    'group_type' => $row['group_type'], // color, select, radio
                    'values' => array()
                );
            }

            $attributes[$idAttributeGroup]['values'][] = array(
                'id_attribute' => (int) $row['id_attribute'],
                'value' => $row['attribute_name']
            );
        }

        return array_values($attributes);
    }

    /**
     * Get price range for products in this category
     */
    protected function getCategoryPriceRange($idCategory)
    {
        $sql = 'SELECT MIN(p.price) as min_price, MAX(p.price) as max_price
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                WHERE cp.id_category = ' . (int) $idCategory . '
                AND p.active = 1';

        $result = Db::getInstance()->getRow($sql);

        if (!$result || $result['min_price'] === null) {
            return array();
        }

        return array(
            'type' => 'price_range',
            'min' => (float) $result['min_price'],
            'max' => (float) $result['max_price'],
            'currency' => $this->context->currency->iso_code
        );
    }

    /**
     * Get manufacturers/brands available in this category
     */
    protected function getCategoryManufacturers($idCategory)
    {
        $idLang = (int) $this->context->language->id;
        
        $sql = 'SELECT DISTINCT m.id_manufacturer, m.name
                FROM ' . _DB_PREFIX_ . 'manufacturer m
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON m.id_manufacturer = p.id_manufacturer
                INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                WHERE cp.id_category = ' . (int) $idCategory . '
                AND p.active = 1
                AND m.active = 1
                ORDER BY m.name';

        $results = Db::getInstance()->executeS($sql);

        if (!$results || empty($results)) {
            return array();
        }

        return array(
            'type' => 'manufacturers',
            'name' => 'Brand',
            'values' => array_map(function($row) {
                return array(
                    'id_manufacturer' => (int) $row['id_manufacturer'],
                    'value' => $row['name']
                );
            }, $results)
        );
    }
}
