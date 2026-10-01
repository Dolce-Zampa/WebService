<?php

/** Keep configurator notes in native PrestaShop customization data, including orders. */
class MlabFactoryCartNotes
{
    const FIELD_NAME = 'Configurazione prodotto';

    public static function validate($notes)
    {
        if (!is_string($notes) || strlen($notes) > 2000 || strpos($notes, "\0") !== false) {
            throw new MlabFactoryApiException('Invalid product notes (maximum 2000 bytes).', 422);
        }
        return trim($notes);
    }

    private static function fieldId($productId)
    {
        $db = Db::getInstance();
        $id = (int) $db->getValue('SELECT cf.id_customization_field FROM `' . _DB_PREFIX_ . 'customization_field` cf
            INNER JOIN `' . _DB_PREFIX_ . 'customization_field_lang` lang ON lang.id_customization_field = cf.id_customization_field
            WHERE cf.id_product = ' . (int) $productId . ' AND cf.type = ' . (int) Product::CUSTOMIZE_TEXTFIELD . '
            AND lang.name = "' . pSQL(self::FIELD_NAME) . '"');
        if ($id > 0) {
            return $id;
        }
        if (!$db->insert('customization_field', array('id_product' => (int) $productId, 'type' => Product::CUSTOMIZE_TEXTFIELD, 'required' => 0, 'is_module' => 1))) {
            throw new MlabFactoryApiException('Unable to create configurator notes field.', 500);
        }
        $id = (int) $db->Insert_ID();
        foreach (Shop::getShops(false, null, true) as $shopId) {
            foreach (Language::getLanguages(false) as $language) {
                if (!$db->insert('customization_field_lang', array('id_customization_field' => $id, 'id_lang' => (int) $language['id_lang'], 'id_shop' => (int) $shopId, 'name' => self::FIELD_NAME))) {
                    throw new MlabFactoryApiException('Unable to name configurator notes field.', 500);
                }
            }
        }
        return $id;
    }

    public static function save(Cart $cart, $productId, $combinationId, $notes)
    {
        $notes = self::validate($notes);
        if ($notes === '') {
            throw new MlabFactoryApiException('Product notes must not be blank.', 422);
        }
        $product = new Product((int) $productId);
        if (!Validate::isLoadedObject($product)) {
            throw new MlabFactoryApiException('Product not found.', 404);
        }
        $fieldId = self::fieldId($productId);
        // Native cart quantity handling requires this flag for customization rows.
        if (!(int) $product->customizable) {
            $product->customizable = 1;
            if (!$product->update()) {
                throw new MlabFactoryApiException('Unable to enable product customization.', 500);
            }
        }
        $id = (int) $cart->addTextFieldToProduct($productId, $fieldId, Product::CUSTOMIZE_TEXTFIELD, $notes, true);
        if ($id <= 0) {
            throw new MlabFactoryApiException('Unable to save configurator notes.', 422);
        }
        // Native API returns the pending row ID, preserving other text/file fields.
        if ($id <= 0 || !Db::getInstance()->update('customization', array('id_product_attribute' => (int) $combinationId, 'id_address_delivery' => (int) $cart->id_address_delivery), 'id_customization = ' . $id)) {
            throw new MlabFactoryApiException('Unable to associate configurator notes with cart row.', 500);
        }
        return $id;
    }

    public static function assertOwned(Cart $cart, $productId, $combinationId, $customizationId)
    {
        $owned = Db::getInstance()->getValue('SELECT id_customization FROM `' . _DB_PREFIX_ . 'customization`
            WHERE id_customization = ' . (int) $customizationId . ' AND id_cart = ' . (int) $cart->id . '
            AND id_product = ' . (int) $productId . ' AND id_product_attribute = ' . (int) $combinationId);
        if (!$owned) {
            throw new MlabFactoryApiException('Customization does not belong to this cart row.', 422);
        }
    }

    public static function get(Cart $cart, $productId, $customizationId)
    {
        if ((int) $customizationId <= 0) {
            return null;
        }
        $notes = Db::getInstance()->getValue('SELECT d.value FROM `' . _DB_PREFIX_ . 'customized_data` d
            INNER JOIN `' . _DB_PREFIX_ . 'customization` c ON c.id_customization = d.id_customization
            INNER JOIN `' . _DB_PREFIX_ . 'customization_field` cf ON cf.id_customization_field = d.`index`
            INNER JOIN `' . _DB_PREFIX_ . 'customization_field_lang` lang ON lang.id_customization_field = cf.id_customization_field
            WHERE c.id_cart = ' . (int) $cart->id . ' AND c.id_product = ' . (int) $productId . '
            AND c.id_customization = ' . (int) $customizationId . ' AND d.type = ' . (int) Product::CUSTOMIZE_TEXTFIELD . '
            AND lang.name = "' . pSQL(self::FIELD_NAME) . '"');
        return $notes === false ? null : (string) $notes;
    }
}
