<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapilogoutModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));

        if ($this->context->cookie) {
            $this->context->cookie->id_customer = null;
            $this->context->cookie->customer_lastname = null;
            $this->context->cookie->customer_firstname = null;
            $this->context->cookie->logged = false;
            $this->context->cookie->is_guest = false;
            $this->context->cookie->passwd = null;
            $this->context->cookie->email = null;
            $this->context->cookie->id_cart = null;
            $this->context->cookie->write();
        }

        $this->context->customer = new Customer();

        return array(
            'message' => 'Logout successful.',
        );
    }
}
