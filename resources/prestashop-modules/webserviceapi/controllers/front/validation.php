<?php

class webserviceapivalidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        if (!$this->module->active) {
            Tools::redirect('index.php?controller=order');
        }

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !(int) $cart->id || !count($cart->getProducts())) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $orderId = (int) Order::getOrderByCartId((int) $cart->id);
        if ($orderId <= 0) {
            $orderStateId = (int) $this->module->getDefaultOrderStateId();
            if ($orderStateId <= 0) {
                Tools::redirect('index.php?controller=order&step=1');
            }

            $this->module->validateOrder(
                (int) $cart->id,
                $orderStateId,
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                $this->module->displayName,
                null,
                array(),
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );

            $orderId = (int) $this->module->currentOrder;
        }

        Tools::redirect(
            'index.php?controller=order-confirmation'
            . '&id_cart=' . (int) $cart->id
            . '&id_module=' . (int) $this->module->id
            . '&id_order=' . (int) $orderId
            . '&key=' . rawurlencode((string) $customer->secure_key)
        );
    }
}