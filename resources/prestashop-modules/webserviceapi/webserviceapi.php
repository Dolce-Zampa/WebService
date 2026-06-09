<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class webserviceapi extends PaymentModule
{
    const CONFIG_PAYMENT_MODULE = 'MLABFACTORYAPI_PAYMENT_MODULE';
    const CONFIG_WEBSERVICE_URL = 'MLABFACTORYAPI_WEBSERVICE_URL';
    const CONFIG_WEBHOOK_SECRET = 'MLABFACTORYAPI_WEBHOOK_SECRET';
    const CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED = 'MLABFACTORYAPI_PRODUCT_SAVED_WEBHOOK_ENABLED';
    const CONFIG_CHATGPT_TEXT_PROMPT  = 'MLABFACTORYAPI_CHATGPT_TEXT_PROMPT';
    const CONFIG_CHATGPT_IMAGE_PROMPT = 'MLABFACTORYAPI_CHATGPT_IMAGE_PROMPT';
    private static array $productWebhookDispatched = [];
    public function __construct()
    {
        $this->name = 'webserviceapi';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'MlabFactory - Marco De Felice';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Webserviceapi API');
        $this->description = $this->l('Expose JSON APIs for customer, cart and order flows secured by PrestaShop webservice keys.');
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('moduleRoutes')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && Configuration::updateValue(self::CONFIG_PAYMENT_MODULE, $this->getDefaultPaymentModule())
            && Configuration::updateValue(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED, 1)
            && Configuration::updateValue(self::CONFIG_CHATGPT_TEXT_PROMPT, '')
            && Configuration::updateValue(self::CONFIG_CHATGPT_IMAGE_PROMPT, '');
    }

    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_PAYMENT_MODULE)
            && Configuration::deleteByName(self::CONFIG_WEBSERVICE_URL)
            && Configuration::deleteByName(self::CONFIG_WEBHOOK_SECRET)
            && Configuration::deleteByName(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED)
            && Configuration::deleteByName(self::CONFIG_CHATGPT_TEXT_PROMPT)
            && Configuration::deleteByName(self::CONFIG_CHATGPT_IMAGE_PROMPT)
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMlabFactoryApi')) {
            $paymentModule   = trim((string) Tools::getValue(self::CONFIG_PAYMENT_MODULE));
            $webserviceUrl   = trim((string) Tools::getValue(self::CONFIG_WEBSERVICE_URL));
            $webhookSecret   = trim((string) Tools::getValue(self::CONFIG_WEBHOOK_SECRET));
            $webhookEnabled  = (int) Tools::getValue(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED, 0);
            $textPrompt      = trim((string) Tools::getValue(self::CONFIG_CHATGPT_TEXT_PROMPT));
            $imagePrompt     = trim((string) Tools::getValue(self::CONFIG_CHATGPT_IMAGE_PROMPT));

            if ($paymentModule === '') {
                $output .= $this->displayError($this->l('Payment module technical name is required.'));
            } else {
                Configuration::updateValue(self::CONFIG_PAYMENT_MODULE, $paymentModule);
                Configuration::updateValue(self::CONFIG_WEBSERVICE_URL, $webserviceUrl);
                Configuration::updateValue(self::CONFIG_WEBHOOK_SECRET, $webhookSecret);
                Configuration::updateValue(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED, $webhookEnabled);
                Configuration::updateValue(self::CONFIG_CHATGPT_TEXT_PROMPT, $textPrompt);
                Configuration::updateValue(self::CONFIG_CHATGPT_IMAGE_PROMPT, $imagePrompt);
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        return $output . $this->renderConfiguration() . $this->renderUsageHelp();
    }

    /**
     * Fired after a product is created in the back office.
     * Notifies the webservice so it can enrich the product via ChatGPT when the
     * product name contains the placeholder string "n.d.".
     */
    public function hookActionObjectProductAddAfter(array $params)
    {
        if (!isset($params['object']) || !($params['object'] instanceof Product)) {
            return;
        }
        $this->notifyProductSavedWebhook($params['object']);
    }

    /**
     * Fired after a product is updated in the back office.
     */
    public function hookActionObjectProductUpdateAfter(array $params)
    {
        if (!isset($params['object']) || !($params['object'] instanceof Product)) {
            return;
        }
        $this->notifyProductSavedWebhook($params['object']);
    }

    /**
     * Sends a webhook notification to the webservice when a product name contains "n.d.".
     *
     * The call is fire-and-forget: we do not block on the response so that the admin
     * save action is not noticeably delayed.
     */
    private function notifyProductSavedWebhook(Product $product)
    {
        $webhookEnabled = (bool) Configuration::get(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED);
        if (!$webhookEnabled) {
            return;
        }

        $webhookBaseUrl = rtrim((string) Configuration::get(self::CONFIG_WEBSERVICE_URL), '/');
        $webhookSecret  = (string) Configuration::get(self::CONFIG_WEBHOOK_SECRET);

        if (empty($webhookBaseUrl) || empty($webhookSecret)) {
            PrestaShopLogger::addLog(
                '[webserviceapi] Webhook not sent: WEBSERVICE_URL or WEBHOOK_SECRET is not configured.',
                2,
                null,
                'Product',
                (int) $product->id
            );
            return;
        }

        $langId      = (int) Configuration::get('PS_LANG_DEFAULT');
        $productName = (string) ($product->name[$langId] ?? '');

        // Only process products whose name contains the "n.d." placeholder
        if (stripos($productName, 'n.d.') === false) {
            return;
        }

        $productId = (int) $product->id;
        if (isset(self::$productWebhookDispatched[$productId])) {
            return;
        }
        self::$productWebhookDispatched[$productId] = true;

        $url     = $webhookBaseUrl . '/api/webhooks/prestashop/product-saved';
        $payload = json_encode([
            'product_id'   => $productId,
            'product_name' => $productName,
            'text_prompt'  => (string) Configuration::get(self::CONFIG_CHATGPT_TEXT_PROMPT),
            'image_prompt' => (string) Configuration::get(self::CONFIG_CHATGPT_IMAGE_PROMPT),
            'source_image_url' => $this->getProductCoverImageUrl($product),
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'X-Webhook-Secret: ' . $webhookSecret,
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            PrestaShopLogger::addLog(
                "[webserviceapi] Webhook failed for product #{$product->id}: HTTP {$httpCode} – {$curlErr}",
                3,
                null,
                'Product',
                (int) $product->id
            );
        }
    }

    private function getProductCoverImageUrl(Product $product): string
    {
        $cover = Product::getCover((int) $product->id);
        $idImage = (int) ($cover['id_image'] ?? 0);
        if ($idImage <= 0 || !isset($this->context->link)) {
            return '';
        }

        $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        $linkRewrite = '';
        if (isset($product->link_rewrite) && is_array($product->link_rewrite)) {
            $linkRewrite = (string) ($product->link_rewrite[$langId] ?? '');
        }

        if ($linkRewrite === '') {
            return '';
        }

        return (string) $this->context->link->getImageLink($linkRewrite, (string) $product->id . '-' . $idImage, 'large_default');
    }

    public function hookModuleRoutes()
    {
        $params = array(
            'fc' => 'module',
            'module' => $this->name,
        );

        return array(
            'module-webserviceapi-register' => array(
                'rule' => 'api/register',
                'keywords' => array(),
                'controller' => 'register',
                'params' => $params,
            ),
            'module-webserviceapi-login' => array(
                'rule' => 'api/login',
                'keywords' => array(),
                'controller' => 'login',
                'params' => $params,
            ),
            'module-webserviceapi-passwordreset' => array(
                'rule' => 'api/password-reset',
                'keywords' => array(),
                'controller' => 'passwordreset',
                'params' => $params,
            ),
            'module-webserviceapi-customer' => array(
                'rule' => 'api/customers',
                'keywords' => array(),
                'controller' => 'customer',
                'params' => $params,
            ),
            'module-webserviceapi-account' => array(
                'rule' => 'api/account',
                'keywords' => array(),
                'controller' => 'account',
                'params' => $params,
            ),
            'module-webserviceapi-addresses' => array(
                'rule' => 'api/addresses',
                'keywords' => array(),
                'controller' => 'addresses',
                'params' => $params,
            ),
            'module-webserviceapi-logout' => array(
                'rule' => 'api/logout',
                'keywords' => array(),
                'controller' => 'logout',
                'params' => $params,
            ),
            'module-webserviceapi-cart' => array(
                'rule' => 'api/carts',
                'keywords' => array(),
                'controller' => 'cart',
                'params' => $params,
            ),
            'module-webserviceapi-order' => array(
                'rule' => 'api/orders',
                'keywords' => array(),
                'controller' => 'order',
                'params' => $params,
            ),
            'module-webserviceapi-coupon' => array(
                'rule' => 'api/cart_rules',
                'keywords' => array(),
                'controller' => 'coupon',
                'params' => $params,
            ),
            'module-webserviceapi-product' => array(
                'rule' => 'api/catalog',
                'keywords' => array(),
                'controller' => 'product',
                'params' => $params,
            ),
            'module-webserviceapi-contact' => array(
                'rule' => 'api/contact',
                'keywords' => array(),
                'controller' => 'contact',
                'params' => $params,
            ),
            'module-webserviceapi-wishlist' => array(
                'rule' => 'api/wishlists',
                'keywords' => array(),
                'controller' => 'wishlist',
                'params' => $params,
            ),
            'module-webserviceapi-productupdate' => array(
                'rule' => 'api/products/update',
                'keywords' => array(),
                'controller' => 'productupdate',
                'params' => $params,
            ),
        );
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        $cart = isset($params['cart']) && Validate::isLoadedObject($params['cart']) ? $params['cart'] : null;
        if (!$cart || !(int) $cart->id) {
            return array();
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName($this->name);
        $paymentOption->setCallToActionText($this->l('Pagamento personalizzato API'));
        $paymentOption->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true));
        $paymentOption->setAdditionalInformation('');

        return array($paymentOption);
    }

    public function hookPaymentReturn($params)
    {
        return '';
    }

    public function getDefaultOrderStateId()
    {
        $candidates = array(
            (int) Configuration::get('PS_OS_PREPARATION'),
            (int) Configuration::get('PS_OS_BANKWIRE'),
            (int) Configuration::get('PS_OS_CHEQUE'),
            (int) Configuration::get('PS_OS_PAYMENT'),
        );

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 0;
    }

    protected function renderConfiguration()
    {
        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Payment module technical name'),
                        'name' => self::CONFIG_PAYMENT_MODULE,
                        'required' => true,
                        'desc' => $this->l('Used when the API finalizes an order, for example ps_wirepayment.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webservice base URL'),
                        'name' => self::CONFIG_WEBSERVICE_URL,
                        'required' => false,
                        'desc' => $this->l('Base URL of the external webservice that handles ChatGPT enrichment (e.g. https://api.myservice.com). Leave empty to disable the product-saved webhook.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook secret'),
                        'name' => self::CONFIG_WEBHOOK_SECRET,
                        'required' => false,
                        'desc' => $this->l('Shared secret sent in the X-Webhook-Secret header to authenticate the product-saved webhook.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable product-saved webhook'),
                        'name' => self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED,
                        'is_bool' => true,
                        'required' => false,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                        'desc' => $this->l('If disabled, the product-saved webhook evolutive flow will not run.'),
                    ),
                    array(
                        'type'     => 'textarea',
                        'label'    => $this->l('ChatGPT text generation prompt'),
                        'name'     => self::CONFIG_CHATGPT_TEXT_PROMPT,
                        'required' => false,
                        'rows'     => 8,
                        'cols'     => 60,
                        'desc'     => $this->l('Custom prompt sent to ChatGPT for SEO text generation. Use {product_name} as placeholder for the product name. Leave empty to use the built-in default prompt.'),
                    ),
                    array(
                        'type'     => 'textarea',
                        'label'    => $this->l('ChatGPT image generation prompt'),
                        'name'     => self::CONFIG_CHATGPT_IMAGE_PROMPT,
                        'required' => false,
                        'rows'     => 4,
                        'cols'     => 60,
                        'desc'     => $this->l('Custom prompt sent to DALL-E for product image generation. Use {product_name} as placeholder for the product name. Leave empty to use the built-in default prompt.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitMlabFactoryApi',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) $this->context->language->id;
        $helper->submit_action = 'submitMlabFactoryApi';
        $helper->fields_value = array(
            self::CONFIG_PAYMENT_MODULE                 => (string) Configuration::get(self::CONFIG_PAYMENT_MODULE),
            self::CONFIG_WEBSERVICE_URL                 => (string) Configuration::get(self::CONFIG_WEBSERVICE_URL),
            self::CONFIG_WEBHOOK_SECRET                 => (string) Configuration::get(self::CONFIG_WEBHOOK_SECRET),
            self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED  => (int) Configuration::get(self::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED, 1),
            self::CONFIG_CHATGPT_TEXT_PROMPT            => (string) Configuration::get(self::CONFIG_CHATGPT_TEXT_PROMPT),
            self::CONFIG_CHATGPT_IMAGE_PROMPT           => (string) Configuration::get(self::CONFIG_CHATGPT_IMAGE_PROMPT),
        );

        return $helper->generateForm(array($fieldsForm));
    }

    protected function renderUsageHelp()
    {
        $baseUrl = $this->context->link->getModuleLink($this->name, 'login', array(), true);

        $html = '<div class="panel">';
        $html .= '<h3>' . $this->l('API usage') . '</h3>';
        $html .= '<p>' . $this->l('All endpoints return JSON and require a PrestaShop webservice key sent as Bearer token, X-WS-Key header, or ws_key query parameter.') . '</p>';
        $html .= '<p><strong>' . $this->l('Base fallback URL') . ':</strong> <code>' . Tools::safeOutput($baseUrl) . '</code></p>';
        $html .= '<p>' . $this->l('Pretty routes are also registered under /api/*.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    protected function getDefaultPaymentModule()
    {
        $candidates = array($this->name, 'ps_wirepayment', 'bankwire', 'ps_checkpayment', 'checkpayment', 'ps_cashondelivery', 'cashondelivery');

        foreach ($candidates as $candidate) {
            if (Module::isInstalled($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
