<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapiaccountModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'PUT', 'PATCH'));

        if ($method === 'GET') {
            return $this->getAccount();
        }

        return $this->updateAccount();
    }

    protected function getAccount()
    {
        $idCustomer = (int) Tools::getValue('id_customer');
        if ($idCustomer <= 0) {
            throw new MlabFactoryApiException('id_customer is required.', 422);
        }

        $customer = MlabFactoryApiHelper::ensureCustomerExists($idCustomer);
        $addresses = $this->getCustomerAddresses($customer);

        return array(
            'message' => 'Customer profile retrieved successfully.',
            'customer' => MlabFactoryApiHelper::serializeCustomer($customer),
            'addresses' => $addresses,
        );
    }

    protected function updateAccount()
    {
        $payload = MlabFactoryApiHelper::getCustomerPayload($this->getJsonPayload());
        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer');
        $customer = MlabFactoryApiHelper::ensureCustomerExists($idCustomer);

        if (array_key_exists('email', $payload)) {
            $email = trim((string) $payload['email']);
            if (!Validate::isEmail($email)) {
                throw new MlabFactoryApiException('Invalid email address.', 422);
            }

            $existing = MlabFactoryApiHelper::getCustomerByEmail($email);
            if ($existing && (int) $existing->id !== (int) $customer->id) {
                throw new MlabFactoryApiException('Email already used by another customer.', 409);
            }

            $customer->email = $email;
        }

        if (array_key_exists('firstname', $payload)) {
            $customer->firstname = trim((string) $payload['firstname']);
        }
        if (array_key_exists('lastname', $payload)) {
            $customer->lastname = trim((string) $payload['lastname']);
        }
        if (array_key_exists('birthday', $payload)) {
            $customer->birthday = (string) $payload['birthday'];
        }
        if (array_key_exists('newsletter', $payload)) {
            $customer->newsletter = (int) MlabFactoryApiHelper::toBool($payload['newsletter']);
        }
        if (array_key_exists('optin', $payload)) {
            $customer->optin = (int) MlabFactoryApiHelper::toBool($payload['optin']);
        }
        if (!empty($payload['password'])) {
            $customer->passwd = MlabFactoryApiHelper::hashPassword((string) $payload['password']);
        }

        if (!$customer->validateFields(false) || !$customer->validateController()) {
            throw new MlabFactoryApiException('Invalid customer payload.', 422);
        }

        if (!$customer->update()) {
            throw new MlabFactoryApiException('Unable to update customer.', 500);
        }

        $updatedAddresses = array();
        if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
            $delivery = $this->upsertAddress($customer, $payload['delivery_address'], 'API delivery');
            $updatedAddresses['delivery'] = MlabFactoryApiHelper::serializeAddress($delivery);
        }
        if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
            $invoice = $this->upsertAddress($customer, $payload['invoice_address'], 'API invoice');
            $updatedAddresses['invoice'] = MlabFactoryApiHelper::serializeAddress($invoice);
        }

        return array(
            'message' => 'Customer profile updated successfully.',
            'customer' => MlabFactoryApiHelper::serializeCustomer($customer),
            'addresses' => !empty($updatedAddresses) ? $updatedAddresses : $this->getCustomerAddresses($customer),
        );
    }

    protected function getCustomerAddresses(Customer $customer)
    {
        $serialized = array();
        foreach ($customer->getAddresses((int) $this->context->language->id) as $addressData) {
            $address = new Address((int) $addressData['id_address']);
            if (Validate::isLoadedObject($address)) {
                $serialized[] = MlabFactoryApiHelper::serializeAddress($address);
            }
        }

        return array(
            'all' => $serialized,
        );
    }

    protected function upsertAddress(Customer $customer, array $data, $defaultAlias)
    {
        if (!empty($data['id_address'])) {
            $address = new Address((int) $data['id_address']);
            if (!Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $customer->id) {
                throw new MlabFactoryApiException('Address does not belong to the customer.', 422, array('id_address' => (int) $data['id_address']));
            }
        } else {
            $address = new Address();
            $address->id_customer = (int) $customer->id;
        }

        if (empty($data['address1']) && !Validate::isLoadedObject($address)) {
            throw new MlabFactoryApiException('Missing required fields.', 422, array('missing_fields' => array('address1', 'city', 'postcode', 'id_country')));
        }

        $address->alias = !empty($data['alias']) ? (string) $data['alias'] : (!empty($address->alias) ? (string) $address->alias : $defaultAlias);
        $address->firstname = !empty($data['firstname']) ? (string) $data['firstname'] : (!empty($address->firstname) ? (string) $address->firstname : (string) $customer->firstname);
        $address->lastname = !empty($data['lastname']) ? (string) $data['lastname'] : (!empty($address->lastname) ? (string) $address->lastname : (string) $customer->lastname);
        $address->company = (string) MlabFactoryApiHelper::getValue($data, 'company', (string) $address->company);
        $address->vat_number = (string) MlabFactoryApiHelper::getValue($data, 'vat_number', (string) $address->vat_number);
        $address->dni = (string) MlabFactoryApiHelper::getValue($data, 'dni', (string) $address->dni);
        $address->address1 = (string) MlabFactoryApiHelper::getValue($data, 'address1', (string) $address->address1);
        $address->address2 = (string) MlabFactoryApiHelper::getValue($data, 'address2', (string) $address->address2);
        $address->postcode = (string) MlabFactoryApiHelper::getValue($data, 'postcode', (string) $address->postcode);
        $address->city = (string) MlabFactoryApiHelper::getValue($data, 'city', (string) $address->city);
        $address->id_country = (int) MlabFactoryApiHelper::getValue($data, 'id_country', (int) $address->id_country ?: 10);
        $address->id_state = (int) MlabFactoryApiHelper::getValue($data, 'id_state', (int) $address->id_state);
        $address->phone = (string) MlabFactoryApiHelper::getValue($data, 'phone', (string) $address->phone);
        $address->phone_mobile = (string) MlabFactoryApiHelper::getValue($data, 'phone_mobile', (string) $address->phone_mobile);
        $address->other = (string) MlabFactoryApiHelper::getValue($data, 'other', (string) $address->other);

        if (!$address->validateFields(false) || !$address->validateController()) {
            throw new MlabFactoryApiException('Invalid address payload.', 422);
        }

        $success = Validate::isLoadedObject($address) ? $address->update() : $address->add();
        if (!$success) {
            throw new MlabFactoryApiException('Unable to save address.', 500);
        }

        return $address;
    }
}
