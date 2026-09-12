<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapicartModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'POST', 'PUT', 'DELETE'));

        if ($method === 'GET') {
            return $this->handleGetRequest();
        }

        if ($method === 'DELETE') {
            return $this->handleDeleteRequest();
        }

        return $this->handleWriteRequest();
    }

    protected function handleWriteRequest()
    {
        $payload = MlabFactoryApiHelper::getCartPayload($this->getJsonPayload());
        MlabFactoryApiHelper::requireFields($payload, array('products'));
        if (!is_array($payload['products'])) {
            throw new MlabFactoryApiException('Products must be an array.', 422);
        }

        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer', 0);
        $idGuestProvided = array_key_exists('id_guest', $payload) ? (int) $payload['id_guest'] : 0;

        // Flag: il guest è stato generato automaticamente (non fornito dal client)
        $guestWasGenerated = false;
        $idGuest = $idGuestProvided;

        if ($idCustomer <= 0 && $idGuestProvided <= 0) {
            $idGuest = $this->createGuest();
            $guestWasGenerated = true;
        }

        $customer = null;
        if ($idCustomer > 0) {
            $customer = MlabFactoryApiHelper::ensureCustomerExists($idCustomer);
        }

        $cart = !empty($payload['id_cart']) ? new Cart((int) $payload['id_cart']) : new Cart();
        if (!empty($payload['id_cart']) && !Validate::isLoadedObject($cart)) {
            throw new MlabFactoryApiException('Cart not found.', 404, array('id_cart' => (int) $payload['id_cart']));
        }

        if ((int) $cart->id > 0) {
            if ($idCustomer > 0 && (int) $cart->id_customer !== $idCustomer) {
                throw new MlabFactoryApiException(
                    'Cart does not belong to the customer.',
                    422,
                    array('id_cart' => (int) $cart->id)
                );
            }

            // Il check sul guest va fatto SOLO se l'utente ha fornito esplicitamente id_guest
            // (altrimenti verifichiamo contro il guest già presente sul carrello)
            if ($idCustomer <= 0) {
                $expectedGuest = $idGuestProvided > 0 ? $idGuestProvided : (int) $cart->id_guest;
                if ($expectedGuest > 0 && (int) $cart->id_guest !== $expectedGuest) {
                    throw new MlabFactoryApiException(
                        'Cart does not belong to the guest.',
                        422,
                        array(
                            'id_cart' => (int) $cart->id,
                            'id_guest' => $expectedGuest,
                            'correct_id_guest' => (int) $cart->id_guest,
                        )
                    );
                }
                // Se il carrello esiste già, mantieni il suo guest invece di sovrascriverlo
                if ($idGuestProvided <= 0 && (int) $cart->id_guest > 0) {
                    $idGuest = (int) $cart->id_guest;
                    $guestWasGenerated = false;
                }
            }
        }

        // --- GESTIONE INDIRIZZI ---
        $deliveryAddress = null;
        if ($customer) {
            if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
                $deliveryAddress = MlabFactoryApiHelper::ensureAddressForCustomer(
                    $customer,
                    $payload['delivery_address'],
                    'API delivery'
                );
            } elseif (isset($payload['id_address_delivery']) && (int) $payload['id_address_delivery'] > 0) {
                $deliveryAddress = MlabFactoryApiHelper::ensureAddressForCustomer(
                    $customer,
                    array('id_address' => (int) $payload['id_address_delivery']),
                    'API delivery'
                );
            }
        }

        $invoiceAddress = null;
        if ($customer) {
            if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
                $invoiceAddress = MlabFactoryApiHelper::ensureAddressForCustomer(
                    $customer,
                    $payload['invoice_address'],
                    'API invoice'
                );
            } elseif (isset($payload['id_address_invoice']) && (int) $payload['id_address_invoice'] > 0) {
                $invoiceAddress = MlabFactoryApiHelper::ensureAddressForCustomer(
                    $customer,
                    array('id_address' => (int) $payload['id_address_invoice']),
                    'API invoice'
                );
            }
        }

        // --- VALUTA ---
        $idCurrency = (int) MlabFactoryApiHelper::getValue(
            $payload,
            'id_currency',
            Configuration::get('PS_CURRENCY_DEFAULT')
        );
        if ($idCurrency > 0 && !Currency::exists($idCurrency)) {
            throw new MlabFactoryApiException(
                'Invalid id_currency.',
                422,
                array('id_currency' => $idCurrency)
            );
        }

        $cart->id_customer = $idCustomer;
        $cart->id_guest = $idGuest;
        $cart->id_currency = $idCurrency;
        $cart->id_lang = (int) MlabFactoryApiHelper::getValue(
            $payload,
            'id_lang',
            $customer ? $customer->id_lang : Configuration::get('PS_LANG_DEFAULT')
        );
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_shop = (int) $this->context->shop->id;

        // secure_key: usa quello del customer, altrimenti genera uno valido per il guest
        if ($customer) {
            $cart->secure_key = (string) $customer->secure_key;
        } elseif (empty($cart->secure_key)) {
            $cart->secure_key = md5(uniqid((string) mt_rand(), true));
        }

        $cart->id_address_delivery = $deliveryAddress
            ? (int) $deliveryAddress->id
            : (int) $cart->id_address_delivery;
        $cart->id_address_invoice = $invoiceAddress
            ? (int) $invoiceAddress->id
            : (int) $cart->id_address_invoice;

        if (!$cart->id) {
            if (!$cart->add()) {
                throw new MlabFactoryApiException('Unable to create cart.', 500);
            }
        } elseif (!$cart->update()) {
            throw new MlabFactoryApiException('Unable to update cart.', 500);
        }

        // --- REPLACE PRODUCTS ---
        $replaceProducts = MlabFactoryApiHelper::getValue($payload, 'replace_products', false);
        if (MlabFactoryApiHelper::toBool($replaceProducts, false)) {
            foreach ($cart->getProducts() as $existingProduct) {
                $existingDeliveryAddress = isset($existingProduct['id_address_delivery'])
                    ? (int) $existingProduct['id_address_delivery']
                    : 0;

                $cart->deleteProduct(
                    (int) $existingProduct['id_product'],
                    (int) $existingProduct['id_product_attribute'],
                    (int) $existingProduct['id_customization'],
                    $existingDeliveryAddress
                );
            }
        }

        // --- AGGIUNTA PRODOTTI ---
        foreach ($payload['products'] as $productLine) {
            if (!is_array($productLine)) {
                throw new MlabFactoryApiException('Each product line must be an object.', 422);
            }

            MlabFactoryApiHelper::requireFields($productLine, array('id_product', 'quantity'));
            $quantity = (int) $productLine['quantity'];

            $productId = (int) $productLine['id_product'];
            $combinationId = (int) MlabFactoryApiHelper::getValue($productLine, 'id_product_attribute', 0);
            $customizationId = (int) MlabFactoryApiHelper::getValue($productLine, 'id_customization', 0);
            $deliveryAddressId = $cart->id_address_delivery ? (int) $cart->id_address_delivery : 0;

            // Validazione operatore
            $operation = MlabFactoryApiHelper::getValue($productLine, 'op', null);
            if ($operation !== null && !in_array($operation, array('up', 'down'), true)) {
                throw new MlabFactoryApiException(
                    'Invalid op value. Allowed: "up", "down".',
                    422,
                    array('op' => $operation)
                );
            }

            // --- GESTIONE CUSTOMIZZAZIONI ---
            $customFields = MlabFactoryApiHelper::getValue($productLine, 'customizations', array());
            $customizationFieldIds = array();

            if (!empty($customFields) && is_array($customFields)) {
                $product = new Product($productId);
                if (!Validate::isLoadedObject($product)) {
                    throw new MlabFactoryApiException(
                        'Product not found.',
                        404,
                        array('id_product' => $productId)
                    );
                }

                $allowedFields = $product->getCustomizationFieldIds();
                $allowedById = array();
                foreach ($allowedFields as $f) {
                    $allowedById[(int) $f['id_customization_field']] = (int) $f['type'];
                }

                foreach ($customFields as $field) {
                    if (!is_array($field)) {
                        throw new MlabFactoryApiException('Each customization field must be an object.', 422);
                    }
                    MlabFactoryApiHelper::requireFields($field, array('id_customization_field', 'value'));
                    $fieldId = (int) $field['id_customization_field'];

                    if (!isset($allowedById[$fieldId])) {
                        throw new MlabFactoryApiException(
                            'Invalid customization field for this product.',
                            422,
                            array(
                                'id_product' => $productId,
                                'id_customization_field' => $fieldId,
                            )
                        );
                    }

                    $type = $allowedById[$fieldId];

                    if ($type == Product::CUSTOMIZE_TEXTFIELD) {
                        $result = $cart->addTextFieldToProduct(
                            $productId,
                            $fieldId,
                            Product::CUSTOMIZE_TEXTFIELD,
                            (string) $field['value'],
                            true
                        );
                    } else {
                        $result = $cart->addPictureToProduct(
                            $productId,
                            $fieldId,
                            Product::CUSTOMIZE_FILE,
                            (string) $field['value'],
                            true
                        );
                    }

                    if (!$result) {
                        throw new MlabFactoryApiException(
                            'Unable to save customization field.',
                            422,
                            array('field' => $field)
                        );
                    }

                    // Salva l'id_customization associato a questo field
                    $customizationFieldIds[$fieldId] = (int) $result;
                }

                // Recupera l'id_customization "di riga" dal carrello dopo l'aggiunta dei field.
                // addTextFieldToProduct/addPictureToProduct creano/riusano un customization
                // legato alla coppia (id_product, id_product_attribute, id_address_delivery).
                $cartCustomizations = $cart->getProductCustomization(
                    $productId,
                    $combinationId,
                    $deliveryAddressId
                );

                if (!empty($cartCustomizations)) {
                    // Usa l'id_customization della riga appena creata
                    $customizationId = (int) $cartCustomizations[0]['id_customization'];
                } elseif (!empty($customizationFieldIds)) {
                    // Fallback: usa l'ultimo id restituito
                    $customizationId = (int) end($customizationFieldIds);
                }
            }
            // --- FINE GESTIONE CUSTOMIZZAZIONI ---

            $updated = $cart->updateQty(
                $quantity,
                $productId,
                $combinationId,
                $customizationId > 0 ? $customizationId : null,
                $operation,
                $deliveryAddressId,
                null,
                true,
                true
            );

            if ($updated <= 0) {
                throw new MlabFactoryApiException(
                    'Unable to add product to cart.',
                    422,
                    array('product' => $productLine)
                );
            }
        }

        // --- CARRIER ---
        $carrierId = (int) MlabFactoryApiHelper::getValue($payload, 'id_carrier', 0);
        if ($carrierId > 0) {
            if (!Carrier::checkCarrierZone($carrierId, (int) $cart->id_address_delivery)) {
                // opzionale: validazione zona carrier
            }
            $cart->id_carrier = $carrierId;
            if ((int) $cart->id_address_delivery > 0) {
                $cart->setDeliveryOption(array((int) $cart->id_address_delivery => $carrierId . ','));
            }
        }

        if (!$cart->update()) {
            throw new MlabFactoryApiException('Unable to persist cart.', 500);
        }

        return array(
            'message' => !empty($payload['id_cart']) ? 'Cart updated successfully.' : 'Cart created successfully.',
            'cart' => MlabFactoryApiHelper::serializeCart($cart),
        );
    }

    protected function handleDeleteRequest()
    {
        $payload = $this->getJsonPayload();

        $idCart = (int) MlabFactoryApiHelper::getValue($payload, 'id_cart', 0);
        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer', 0);

        if ($idCart <= 0) {
            throw new MlabFactoryApiException('You must provide id_cart.', 422);
        }

        if ($idCustomer <= 0) {
            throw new MlabFactoryApiException('You must provide id_customer.', 422);
        }

        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart)) {
            throw new MlabFactoryApiException('Cart not found.', 404, array('id_cart' => $idCart));
        }

        if ((int) $cart->id_customer !== $idCustomer) {
            throw new MlabFactoryApiException(
                'Cart does not belong to the customer.',
                422,
                array('id_cart' => $idCart, 'id_customer' => $idCustomer)
            );
        }

        $linkedOrder = (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_cart` = ' . $idCart . ' LIMIT 1'
        );
        if ($linkedOrder > 0) {
            throw new MlabFactoryApiException(
                'Cart is linked to an existing order and cannot be deleted.',
                422,
                array('id_cart' => $idCart, 'id_order' => $linkedOrder)
            );
        }

        foreach ($cart->getProducts() as $product) {
            $productDeliveryAddress = isset($product['id_address_delivery'])
                ? (int) $product['id_address_delivery']
                : 0;

            $cart->deleteProduct(
                (int) $product['id_product'],
                (int) $product['id_product_attribute'],
                (int) $product['id_customization'],
                $productDeliveryAddress
            );
        }

        if (!$cart->delete()) {
            throw new MlabFactoryApiException('Unable to delete cart.', 500, array('id_cart' => $idCart));
        }

        return array(
            'message' => 'Cart deleted successfully.',
            'id_cart' => $idCart,
        );
    }

    protected function handleGetRequest()
    {
        $idCustomer = (int) Tools::getValue('id_customer');
        $idGuest = (int) Tools::getValue('id_guest');
        $idCart = (int) Tools::getValue('id_cart');

        if ($idCustomer <= 0 && $idGuest <= 0) {
            throw new MlabFactoryApiException('You must provide id_customer or id_guest.', 422);
        }

        if ($idCustomer > 0 && $idGuest > 0) {
            throw new MlabFactoryApiException('Provide only one owner identifier: id_customer or id_guest.', 422);
        }

        $cart = $idCart > 0
            ? $this->getCartById($idCart, $idCustomer, $idGuest)
            : $this->getLatestOpenCart($idCustomer, $idGuest);

        return array(
            'message' => 'Cart retrieved successfully.',
            'cart' => MlabFactoryApiHelper::serializeCart($cart),
        );
    }

    protected function getCartById($idCart, $idCustomer, $idGuest)
    {
        $cart = new Cart((int) $idCart);
        if (!Validate::isLoadedObject($cart)) {
            throw new MlabFactoryApiException('Cart not found.', 404, array('id_cart' => (int) $idCart));
        }

        if ($idCustomer > 0 && (int) $cart->id_customer !== (int) $idCustomer) {
            throw new MlabFactoryApiException(
                'Cart does not belong to the customer.',
                422,
                array('id_cart' => (int) $idCart)
            );
        }

        if ($idGuest > 0 && (int) $cart->id_guest !== (int) $idGuest) {
            throw new MlabFactoryApiException(
                'Cart does not belong to the guest.',
                422,
                array('id_cart' => (int) $idCart)
            );
        }

        return $cart;
    }

    protected function getLatestOpenCart($idCustomer, $idGuest)
    {
        $where = $idCustomer > 0
            ? 'c.`id_customer` = ' . (int) $idCustomer
            : 'c.`id_guest` = ' . (int) $idGuest;

        $shopFilter = '';
        if (isset($this->context->shop) && Validate::isLoadedObject($this->context->shop)) {
            $shopFilter = ' AND c.`id_shop` = ' . (int) $this->context->shop->id;
        }

        $cartId = (int) Db::getInstance()->getValue(
            'SELECT c.`id_cart`
            FROM `' . _DB_PREFIX_ . 'cart` c
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.`id_cart` = c.`id_cart`)
            WHERE ' . $where . $shopFilter . ' AND o.`id_order` IS NULL
            ORDER BY c.`date_upd` DESC, c.`id_cart` DESC
            LIMIT 1'
        );

        if ($cartId <= 0) {
            throw new MlabFactoryApiException(
                'No open cart found for the requested owner.',
                404,
                array(
                    'id_customer' => (int) $idCustomer,
                    'id_guest' => (int) $idGuest,
                )
            );
        }

        return new Cart($cartId);
    }

    protected function createGuest()
    {
        $guest = new Guest();
        $guest->id_operating_system = 0;
        $guest->id_web_browser = 0;
        $guest->accept_language = '';
        $guest->mobile_theme = false;

        if (!$guest->add()) {
            throw new MlabFactoryApiException('Unable to create guest.', 500);
        }

        return (int) $guest->id;
    }
}