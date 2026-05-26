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

            $amountPaid = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $this->addCustomCartRule($cart, $amountPaid);

            $this->module->validateOrder(
                (int) $cart->id,
                $orderStateId,
                $amountPaid,
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

    private function addCustomCartRule(Cart $cart, float $amountPaid)
    {
        $cart = new Cart((int) $cart->id);

        // Totale reale calcolato da PrestaShop
        $psTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Totale deciso dalla tua API
        $customTotal = (float) $amountPaid;

        // Differenza
        $difference = round($psTotal - $customTotal, 2);

        // Applichiamo regola solo se necessario
        if (abs($difference) > 0.01) {

            $cartRule = new CartRule();

            $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

            $cartRule->name = [
                $defaultLang => 'Custom API price adjustment'
            ];

            $cartRule->id_customer = (int) $cart->id_customer;
            $cartRule->quantity = 1;
            $cartRule->quantity_per_user = 1;

            // Se differenza positiva → sconto
            if ($difference > 0) {
                $cartRule->reduction_amount = $difference;
                $cartRule->reduction_tax = true;
            }
            // Se differenza negativa → sovrapprezzo
            else {
                // Creiamo un prodotto fee invece (vedi sotto)
                throw new Exception('Sovrapprezzo: meglio usare prodotto fee');
            }

            $cartRule->date_from = date('Y-m-d H:i:s');
            $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $cartRule->active = 1;
            $cartRule->add();

            $cart->addCartRule($cartRule->id);
        }
    }
}