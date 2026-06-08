<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class webserviceapi extends PaymentModule
{
    const CONFIG_PAYMENT_MODULE = 'MLABFACTORYAPI_PAYMENT_MODULE';
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
            && Configuration::updateValue(self::CONFIG_PAYMENT_MODULE, $this->getDefaultPaymentModule());
    }

    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_PAYMENT_MODULE)
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMlabFactoryApi')) {
            $paymentModule = trim((string) Tools::getValue(self::CONFIG_PAYMENT_MODULE));

            if ($paymentModule === '') {
                $output .= $this->displayError($this->l('Payment module technical name is required.'));
            } else {
                Configuration::updateValue(self::CONFIG_PAYMENT_MODULE, $paymentModule);
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        return $output . $this->renderConfiguration() . $this->renderUsageHelp();
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
            self::CONFIG_PAYMENT_MODULE => (string) Configuration::get(self::CONFIG_PAYMENT_MODULE),
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
