<?php
require_once __DIR__ . '/MlabFactoryApiException.php';
require_once __DIR__ . '/MlabFactoryApiHelper.php';
require_once __DIR__ . '/MlabFactoryCoupon.php';

abstract class MlabFactoryApiBaseModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;
    public $content_only = true;

    /** @var array|null */
    protected $jsonPayload;

    /** @var array|null */
    protected $webserviceAccount;

    public function initContent()
    {
    }

    public function postProcess()
    {
        try {
            $this->authenticate();
            $data = $this->handleRequest();
            $this->renderJson(array('success' => true, 'data' => $data), 200);
        } catch (MlabFactoryApiException $exception) {
            $this->renderJson(array(
                'success' => false,
                'error' => array(
                    'message' => $exception->getMessage(),
                    'details' => $exception->getDetails(),
                ),
            ), $exception->getStatusCode());
        } catch (PrestaShopDatabaseException $exception) {
            $this->renderJson(array(
                'success' => false,
                'error' => array('message' => $exception->getMessage()),
            ), 500);
        } catch (PrestaShopException $exception) {
            $this->renderJson(array(
                'success' => false,
                'error' => array('message' => $exception->getMessage()),
            ), 500);
        }
    }

    abstract protected function handleRequest();

    protected function assertRequestMethod(array $allowedMethods)
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        if (!in_array($method, $allowedMethods, true)) {
            throw new MlabFactoryApiException('Unsupported HTTP method.', 405, array('allowed_methods' => $allowedMethods));
        }
    }

    protected function authenticate()
    {
        $key = $this->extractWebserviceKey();
        if ($key === '') {
            throw new MlabFactoryApiException('Missing webservice key.', 401);
        }

        $account = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'webservice_account` WHERE `key` = \'' . pSQL($key) . '\' AND `active` = 1'
        );

        if (empty($account)) {
            throw new MlabFactoryApiException('Invalid webservice key.', 401);
        }

        $this->webserviceAccount = $account;
    }

    protected function extractWebserviceKey()
    {
        $headers = $this->getRequestHeaders();
        $authorization = isset($headers['Authorization']) ? trim((string) $headers['Authorization']) : '';

        if ($authorization !== '' && preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        if (!empty($headers['X-WS-Key'])) {
            return trim((string) $headers['X-WS-Key']);
        }

        return trim((string) Tools::getValue('ws_key'));
    }

    protected function getRequestHeaders()
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $normalized = array();
                foreach ($headers as $name => $value) {
                    $normalized[$this->normalizeHeaderName($name)] = $value;
                }

                return $normalized;
            }
        }

        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    protected function normalizeHeaderName($name)
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', (string) $name))));
    }

    protected function getJsonPayload()
    {
        if ($this->jsonPayload !== null) {
            return $this->jsonPayload;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            $this->jsonPayload = array();

            return $this->jsonPayload;
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new MlabFactoryApiException('Request body must be valid JSON.', 400);
        }

        $this->jsonPayload = $decoded;

        return $this->jsonPayload;
    }

    protected function renderJson(array $payload, $statusCode)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code((int) $statusCode);
        die(json_encode($payload));
    }

    protected function getCustomerContext(Customer $customer)
    {
        $this->context->customer = $customer;
        $this->context->language = new Language((int) ($customer->id_lang ?: Configuration::get('PS_LANG_DEFAULT')));
        $this->context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        if ($this->context->cookie) {
            $this->context->cookie->id_customer = (int) $customer->id;
            $this->context->cookie->customer_lastname = (string) $customer->lastname;
            $this->context->cookie->customer_firstname = (string) $customer->firstname;
            $this->context->cookie->logged = true;
            $this->context->cookie->is_guest = false;
            $this->context->cookie->passwd = (string) $customer->passwd;
            $this->context->cookie->email = (string) $customer->email;
            $this->context->cookie->id_lang = (int) $this->context->language->id;
            $this->context->cookie->write();
        }
    }
}
