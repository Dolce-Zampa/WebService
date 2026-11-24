<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapiloginModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));

        $payload = MlabFactoryApiHelper::getCustomerPayload($this->getJsonPayload());
        MlabFactoryApiHelper::requireFields($payload, array('email', 'password'));

        $email = trim((string) $payload['email']);
        $password = (string) $payload['password'];

        if (!Validate::isEmail($email)) {
            throw new MlabFactoryApiException('Invalid email address.', 422);
        }

        $customer = MlabFactoryApiHelper::getCustomerByEmail($email);
        if (!$customer || !Validate::isLoadedObject($customer) || !MlabFactoryApiHelper::verifyPassword($password, $customer->passwd)) {
            throw new MlabFactoryApiException('Invalid credentials.', 401);
        }

        if (!(bool) $customer->active) {
            throw new MlabFactoryApiException('Customer account is disabled.', 403);
        }

        if (!Db::getInstance()->update('customer', array('last_login' => date('Y-m-d H:i:s')), 'id_customer = ' . (int) $customer->id)) {
            throw new MlabFactoryApiException('Unable to record customer login.', 500);
        }

        $this->getCustomerContext($customer);

        $addresses = array();
        foreach ($customer->getAddresses((int) $this->context->language->id) as $addressData) {
            $address = new Address((int) $addressData['id_address']);
            if (Validate::isLoadedObject($address)) {
                $addresses[] = MlabFactoryApiHelper::serializeAddress($address);
            }
        }

        return array(
            'message' => 'Login successful.',
            'customer' => MlabFactoryApiHelper::serializeCustomer($customer),
            'addresses' => $addresses,
        );
    }
}
