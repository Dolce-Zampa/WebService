<?php
require_once __DIR__ . '/MlabFactoryApiException.php';

class MlabFactoryApiHelper
{
    public static function getArrayValue(array $data, $key, array $fallbackKeys = array())
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($fallbackKeys as $fallbackKey) {
            if (array_key_exists($fallbackKey, $data)) {
                return $data[$fallbackKey];
            }
        }

        return null;
    }

    public static function getValue(array $data, $key, $default = null)
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        return $default;
    }

    public static function requireFields(array $data, array $fields)
    {
        $missing = array();

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new MlabFactoryApiException('Missing required fields.', 422, array('missing_fields' => $missing));
        }
    }

    public static function toBool($value, $default = false)
    {
        if ($value === null || $value === '') {
            return (bool) $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'y', 'on'), true);
    }

    public static function getCustomerPayload(array $payload)
    {
        $customer = self::getArrayValue($payload, 'customer');

        if (is_array($customer)) {
            return $customer;
        }

        return $payload;
    }

    public static function getCartPayload(array $payload)
    {
        $cart = self::getArrayValue($payload, 'cart');

        if (is_array($cart)) {
            return $cart;
        }

        return $payload;
    }

    public static function hashPassword($plainPassword)
    {
        $plainPassword = (string) $plainPassword;

        if (method_exists('Tools', 'hash')) {
            return Tools::hash($plainPassword);
        }

        return Tools::encrypt($plainPassword);
    }

    public static function verifyPassword($plainPassword, $hashedPassword)
    {
        $plainPassword = (string) $plainPassword;
        $hashedPassword = (string) $hashedPassword;

        if ($hashedPassword === '') {
            return false;
        }

        if (function_exists('password_verify') && password_verify($plainPassword, $hashedPassword)) {
            return true;
        }

        return Tools::encrypt($plainPassword) === $hashedPassword;
    }

    public static function getCustomerByEmail($email)
    {
        $customerId = (int) Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer` WHERE `email` = \'' . pSQL($email) . '\''
        );

        if ($customerId <= 0) {
            return null;
        }

        $customer = new Customer($customerId);

        if (!Validate::isLoadedObject($customer)) {
            return null;
        }

        return $customer;
    }

    public static function createCustomerFromPayload(array $payload, $defaultActive)
    {
        $email = trim((string) self::getValue($payload, 'email'));
        if (!Validate::isEmail($email)) {
            throw new MlabFactoryApiException('Invalid email address.', 422);
        }

        if (self::getCustomerByEmail($email)) {
            throw new MlabFactoryApiException('Customer already exists.', 409, array('email' => $email));
        }

        $customer = new Customer();
        $customer->id_gender = (int) self::getValue($payload, 'id_gender', 0);
        $customer->firstname = trim((string) self::getValue($payload, 'firstname'));
        $customer->lastname = trim((string) self::getValue($payload, 'lastname'));
        $customer->email = $email;
        $customer->passwd = self::hashPassword(self::getValue($payload, 'password'));
        $customer->birthday = (string) self::getValue($payload, 'birthday', '');
        $customer->newsletter = (int) self::toBool(self::getValue($payload, 'newsletter', false));
        $customer->optin = (int) self::toBool(self::getValue($payload, 'optin', false));
        $customer->id_default_group = (int) self::getValue($payload, 'id_default_group', Configuration::get('PS_CUSTOMER_GROUP'));
        $customer->active = (int) self::toBool(self::getValue($payload, 'active', $defaultActive), $defaultActive);
        $customer->id_lang = (int) self::getValue($payload, 'id_lang', Configuration::get('PS_LANG_DEFAULT'));
        $customer->secure_key = md5(uniqid((string) mt_rand(), true));

        if (!$customer->validateFields(false) || !$customer->validateController()) {
            throw new MlabFactoryApiException('Invalid customer payload.', 422);
        }

        if (!$customer->add()) {
            throw new MlabFactoryApiException('Unable to create customer.', 500);
        }

        return $customer;
    }

    public static function serializeCustomer(Customer $customer)
    {
        return array(
            'id' => (int) $customer->id,
            'id_default_group' => (int) $customer->id_default_group,
            'id_gender' => (int) $customer->id_gender,
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
            'birthday' => (string) $customer->birthday,
            'newsletter' => (bool) $customer->newsletter,
            'optin' => (bool) $customer->optin,
            'active' => (bool) $customer->active,
            'secure_key' => (string) $customer->secure_key,
        );
    }

    public static function serializeAddress(Address $address)
    {
        return array(
            'id' => (int) $address->id,
            'alias' => (string) $address->alias,
            'firstname' => (string) $address->firstname,
            'lastname' => (string) $address->lastname,
            'company' => (string) $address->company,
            'address1' => (string) $address->address1,
            'address2' => (string) $address->address2,
            'postcode' => (string) $address->postcode,
            'city' => (string) $address->city,
            'id_country' => (int) $address->id_country,
            'id_state' => (int) $address->id_state,
            'phone' => (string) $address->phone,
            'phone_mobile' => (string) $address->phone_mobile,
            'dni' => (string) $address->dni,
            'vat_number' => (string) $address->vat_number,
        );
    }

    public static function serializeCart(Cart $cart)
    {
        $products = array();
        foreach ($cart->getProducts() as $product) {
            $products[] = array(
                'id_product' => (int) $product['id_product'],
                'id_product_attribute' => (int) $product['id_product_attribute'],
                'id_customization' => (int) $product['id_customization'],
                'name' => (string) $product['name'],
                'reference' => (string) $product['reference'],
                'quantity' => (int) $product['cart_quantity'],
                'price_wt' => (float) $product['price_wt'],
                'total_wt' => (float) $product['total_wt'],
            );
        }

        return array(
            'id' => (int) $cart->id,
            'id_customer' => (int) $cart->id_customer,
            'id_guest' => (int) $cart->id_guest,
            'id_currency' => (int) $cart->id_currency,
            'id_lang' => (int) $cart->id_lang,
            'id_address_delivery' => (int) $cart->id_address_delivery,
            'id_address_invoice' => (int) $cart->id_address_invoice,
            'id_carrier' => (int) $cart->id_carrier,
            'secure_key' => (string) $cart->secure_key,
            'totals' => array(
                'products_tax_incl' => (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS),
                'shipping_tax_incl' => (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING),
                'grand_total_tax_incl' => (float) $cart->getOrderTotal(true, Cart::BOTH),
            ),
            'products' => $products,
        );
    }

    public static function serializeOrder(Order $order)
    {
        $orderRows = array();
        foreach ($order->getProducts() as $product) {
            $orderRows[] = array(
                'id_product' => (int) $product['product_id'],
                'id_product_attribute' => (int) $product['product_attribute_id'],
                'reference' => (string) $product['product_reference'],
                'name' => (string) $product['product_name'],
                'quantity' => (int) $product['product_quantity'],
                'unit_price_tax_incl' => (float) $product['unit_price_tax_incl'],
                'total_price_tax_incl' => (float) $product['total_price_tax_incl'],
            );
        }

        $deliveryAddress = new Address((int) $order->id_address_delivery);
        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);

        return array(
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
            'id_cart' => (int) $order->id_cart,
            'id_customer' => (int) $order->id_customer,
            'id_currency' => (int) $order->id_currency,
            'current_state' => (int) $order->current_state,
            'current_state_name' => (string) self::getOrderStateName((int) $order->current_state, (int) $order->id_lang),
            'payment' => (string) $order->payment,
            'module' => (string) $order->module,
            'is_virtual' => (bool) $order->isVirtual(),
            'date_add' => (string) $order->date_add,
            'total_paid_tax_incl' => (float) $order->total_paid_tax_incl,
            'total_paid_tax_excl' => (float) $order->total_paid_tax_excl,
            'total_products_wt' => (float) $order->total_products_wt,
            'total_products' => (float) $order->total_products,
            'total_shipping_tax_incl' => (float) $order->total_shipping_tax_incl,
            'total_shipping_tax_excl' => (float) $order->total_shipping_tax_excl,
            'totals' => array(
                'products_tax_incl' => (float) $order->total_products_wt,
                'products_tax_excl' => (float) $order->total_products,
                'shipping_tax_incl' => (float) $order->total_shipping_tax_incl,
                'shipping_tax_excl' => (float) $order->total_shipping_tax_excl,
                'discounts_tax_incl' => (float) $order->total_discounts_tax_incl,
                'discounts_tax_excl' => (float) $order->total_discounts_tax_excl,
                'paid_tax_incl' => (float) $order->total_paid_tax_incl,
                'paid_tax_excl' => (float) $order->total_paid_tax_excl,
            ),
            'currency' => array(
                'id' => (int) $currency->id,
                'iso_code' => (string) $currency->iso_code,
                'sign' => (string) $currency->sign,
            ),
            'customer' => Validate::isLoadedObject($customer) ? self::serializeCustomer($customer) : null,
            'delivery_address' => Validate::isLoadedObject($deliveryAddress) ? self::serializeAddress($deliveryAddress) : null,
            'invoice_address' => Validate::isLoadedObject($invoiceAddress) ? self::serializeAddress($invoiceAddress) : null,
            'products' => $orderRows,
        );
    }

    public static function getOrderStateName($orderStateId, $languageId)
    {
        if ($orderStateId <= 0) {
            return '';
        }

        $languageId = $languageId > 0 ? (int) $languageId : (int) Configuration::get('PS_LANG_DEFAULT');

        return (string) Db::getInstance()->getValue(
            'SELECT osl.`name`
            FROM `' . _DB_PREFIX_ . 'order_state_lang` osl
            WHERE osl.`id_order_state` = ' . (int) $orderStateId . '
              AND osl.`id_lang` = ' . $languageId
        );
    }

    public static function ensureCustomerExists($customerId)
    {
        $customer = new Customer((int) $customerId);
        if (!Validate::isLoadedObject($customer)) {
            throw new MlabFactoryApiException('Customer not found.', 404, array('id_customer' => (int) $customerId));
        }

        return $customer;
    }

    public static function createCustomerFromGuest(Cart $cart, array $payload)
    {
        $guest = new Guest((int) $cart->id_guest);
        if (!Validate::isLoadedObject($guest)) {
            throw new MlabFactoryApiException('Guest not found.', 404, array('id_guest' => (int) $cart->id_guest));
        }

        // Get email from payload
        $email = trim((string) self::getValue($payload, 'email', ''));
        if (empty($email)) {
            throw new MlabFactoryApiException('Email is required for guest checkout.', 422);
        }

        // Check if customer already exists with this email
        $existingCustomerId = (int) Customer::customerExists($email, true);
        
        if ($existingCustomerId > 0) {
            // Customer already exists - use existing customer
            $existingCustomer = new Customer($existingCustomerId);
            
            if (!Validate::isLoadedObject($existingCustomer)) {
                throw new MlabFactoryApiException('Customer found but cannot be loaded.', 500, array('id_customer' => $existingCustomerId));
            }
            
            // If customer was a guest and wants to upgrade to registered, update it
            $shouldRegister = self::toBool(self::getValue($payload, 'create_account', false));
            if ($existingCustomer->is_guest && $shouldRegister) {
                $existingCustomer->is_guest = 0;
                $existingCustomer->active = 1;
                $existingCustomer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
                
                // Update password if provided
                $plainPassword = trim((string) self::getValue($payload, 'password', ''));
                if (!empty($plainPassword)) {
                    $existingCustomer->passwd = Tools::encrypt($plainPassword);
                } else {
                    $plainPassword = Tools::passwdGen(8);
                    $existingCustomer->passwd = Tools::encrypt($plainPassword);
                }
                
                if ($existingCustomer->update()) {
                    // Send welcome email for the upgrade
                    Mail::Send(
                        (int) Configuration::get('PS_LANG_DEFAULT'),
                        'account',
                        Mail::l('Welcome!'),
                        array(
                            '{firstname}' => $existingCustomer->firstname,
                            '{lastname}' => $existingCustomer->lastname,
                            '{email}' => $existingCustomer->email,
                            '{passwd}' => $plainPassword
                        ),
                        $existingCustomer->email,
                        $existingCustomer->firstname . ' ' . $existingCustomer->lastname
                    );
                }
            }
            
            return $existingCustomer;
        }

        // Customer doesn't exist - create new one
        $shouldRegister = self::toBool(self::getValue($payload, 'create_account', false));
        
        if (!$shouldRegister) {
            // Guest checkout - create temporary customer
            $customer = new Customer();
            $customer->email = $email;
            $customer->firstname = trim((string) self::getValue($payload, 'firstname', 'Guest'));
            $customer->lastname = trim((string) self::getValue($payload, 'lastname', 'Customer'));
            $customer->passwd = md5(pSQL(_COOKIE_KEY_ . time()));
            $customer->is_guest = 1;
            $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
            
            if (!$customer->validateFields(false) || !$customer->add()) {
                throw new MlabFactoryApiException('Unable to create guest customer.', 500);
            }

            return $customer;
        }

        // Create registered account
        $customer = new Customer();
        $customer->email = $email;
        $customer->firstname = trim((string) self::getValue($payload, 'firstname', 'Guest'));
        $customer->lastname = trim((string) self::getValue($payload, 'lastname', 'Customer'));

        // SAVE DELIVERY ADDRESS FIRST TO GET ID FOR CUSTOMER VALIDATION
        if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
            $deliveryAddress = self::ensureAddressForCustomer($customer, $payload['delivery_address']);
            $customer->id_address_delivery = (int) $deliveryAddress->id;
        }

        // SAVE INVOICE ADDRESS FIRST TO GET ID FOR CUSTOMER VALIDATION
        if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
            $invoiceAddress = self::ensureAddressForCustomer($customer, $payload['invoice_address']);
            $customer->id_address_invoice = (int) $invoiceAddress->id;
        }
        
        // Generate password if provided, otherwise random
        $plainPassword = trim((string) self::getValue($payload, 'password', ''));
        if (empty($plainPassword)) {
            $plainPassword = Tools::passwdGen(8);
        }
        $customer->passwd = Tools::encrypt($plainPassword);
        
        $customer->is_guest = 0;
        $customer->active = 1;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $customer->newsletter = self::toBool(self::getValue($payload, 'newsletter', false));
        $customer->optin = self::toBool(self::getValue($payload, 'optin', false));

        if (!$customer->validateFields(false) || !$customer->add()) {
            throw new MlabFactoryApiException('Unable to create customer account.', 500);
        }

        // Send welcome email if configured
        if (!Mail::Send(
            (int) Configuration::get('PS_LANG_DEFAULT'),
            'account',
            Mail::l('Welcome!'),
            array(
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{passwd}' => $plainPassword
            ),
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        )) {
            // Email send failure is not critical, just log it
            PrestaShopLogger::addLog('Unable to send welcome email to: ' . $customer->email, 2);
        }

        return $customer;
    }


    public static function ensureAddressForCustomer(Customer $customer, array $data)
    {
        if (!empty($data['id_address'])) {
            $address = new Address((int) $data['id_address']);
            if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $customer->id) {
                throw new MlabFactoryApiException('Address does not belong to the customer.', 422, array('id_address' => (int) $data['id_address']));
            }

            return $address;
        }

        self::requireFields($data, array('address1', 'city', 'postcode', 'id_country'));

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = !empty($data['alias']) ? (string) $data['alias'] : 'home';
        $address->firstname = !empty($data['firstname']) ? (string) $data['firstname'] : (string) $customer->firstname;
        $address->lastname = !empty($data['lastname']) ? (string) $data['lastname'] : (string) $customer->lastname;
        $address->company = (string) self::getValue($data, 'company', '');
        $address->vat_number = (string) self::getValue($data, 'vat_number', '');
        $address->dni = (string) self::getValue($data, 'dni', '');
        $address->address1 = (string) $data['address1'];
        $address->address2 = (string) self::getValue($data, 'address2', '');
        $address->postcode = (string) $data['postcode'];
        $address->city = (string) $data['city'];
        $address->id_country = 10; //FIXME: (int) $data['id_country'];
        $address->id_state = (int) self::getValue($data, 'id_state', 0);
        $address->phone = (string) self::getValue($data, 'phone', '');
        $address->phone_mobile = (string) self::getValue($data, 'phone_mobile', '');
        $address->other = (string) self::getValue($data, 'other', '');

        if (!$address->validateFields(false) || !$address->validateController()) {
            throw new MlabFactoryApiException('Invalid address payload.', 422);
        }

        if (!$address->add()) {
            throw new MlabFactoryApiException('Unable to create address.', 500);
        }

        return $address;
    }

    public static function resolveCarrierId(Cart $cart, array $payload)
    {
        $carrierId = (int) self::getValue($payload, 'id_carrier', 0);
        if ($carrierId > 0) {
            $carrier = new Carrier($carrierId);
            if (!Validate::isLoadedObject($carrier)) {
                throw new MlabFactoryApiException('Carrier not found.', 404, array('id_carrier' => $carrierId));
            }

            return $carrierId;
        }

        if ((int) $cart->isVirtualCart()) {
            return 0;
        }

        if ((int) $cart->id_carrier > 0) {
            return (int) $cart->id_carrier;
        }

        throw new MlabFactoryApiException('A carrier is required to finalize a non-virtual cart.', 422);
    }

    public static function resolvePaymentModule($moduleName)
    {
        $moduleName = trim((string) $moduleName);
        if ($moduleName === '') {
            throw new MlabFactoryApiException('Payment module is not configured.', 500);
        }

        $module = Module::getInstanceByName($moduleName);
        if (!Validate::isLoadedObject($module) || !$module->active || !($module instanceof PaymentModule)) {
            throw new MlabFactoryApiException('Configured payment module is not available.', 500, array('payment_module' => $moduleName));
        }

        return $module;
    }
}
