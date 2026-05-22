<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapiaddressesModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('GET', 'PUT', 'PATCH'));

        if ($method === 'GET') {
            return $this->getAddresses();
        }

        return $this->updateAddresses();
    }

    protected function getAddresses(): array
    {
        $idCustomer = (int) Tools::getValue('id_customer');
        if ($idCustomer <= 0) {
            throw new MlabFactoryApiException('id_customer is required.', 422);
        }

        $customer = MlabFactoryApiHelper::ensureCustomerExists($idCustomer);
        $addresses = array();
        foreach ($customer->getAddresses((int) $this->context->language->id) as $addressData) {
            $address = new Address((int) $addressData['id_address']);
            if (Validate::isLoadedObject($address)) {
                $addresses[] = MlabFactoryApiHelper::serializeAddress($address);
            }
        }

        return array(
            'message' => 'Customer addresses retrieved successfully.',
            'addresses' => $addresses,
        );
    }

    protected function updateAddresses(): array
    {
        $payload = MlabFactoryApiHelper::getCustomerPayload($this->getJsonPayload());
        $idCustomer = (int) MlabFactoryApiHelper::getValue($payload, 'id_customer');
        $customer = MlabFactoryApiHelper::ensureCustomerExists($idCustomer);

        $result = array();
        if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
            $deliveryAddress = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['delivery_address']);
            $result['delivery'] = MlabFactoryApiHelper::serializeAddress($deliveryAddress);
        }

        if (!empty($payload['invoice_address']) && is_array($payload['invoice_address'])) {
            $invoiceAddress = MlabFactoryApiHelper::ensureAddressForCustomer($customer, $payload['invoice_address']);
            $result['invoice'] = MlabFactoryApiHelper::serializeAddress($invoiceAddress);
        }

        if (empty($result)) {
            throw new MlabFactoryApiException('You must provide delivery_address and/or invoice_address.', 422);
        }

        return array(
            'message' => 'Customer addresses updated successfully.',
            'addresses' => $result,
        );
    }
}
