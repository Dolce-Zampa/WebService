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

            // Se differenza positiva → Sconto (OK, teniamo la tua logica originale)
            if ($difference > 0) {
                $cartRule = new CartRule();
                $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
                $cartRule->name = [$defaultLang => 'Custom API price adjustment'];
                $cartRule->id_customer = (int) $cart->id_customer;
                $cartRule->quantity = 1;
                $cartRule->quantity_per_user = 1;
                $cartRule->reduction_amount = $difference;
                $cartRule->reduction_tax = true;
                $cartRule->date_from = date('Y-m-d H:i:s');
                $cartRule->date_to = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $cartRule->active = 1;
                $cartRule->add();
                $cart->addCartRule($cartRule->id);
            }
            // Se differenza negativa → Sovrapprezzo (usiamo un PRODOTTO)
            else {
                $fee_amount = abs($difference); // Importo del sovrapprezzo (positivo)

                // 1. Definisci l'ID di un prodotto "fantasma" che userai come fee
                //    (es. ID 9999 - crealo manualmente dal backoffice, disabilitalo dalla vetrina)
                $id_product_fee = 151;

                // 2. Imposta un prezzo specifico temporaneo per questo prodotto/carrello
                $specific_price = new SpecificPrice();
                $specific_price->id_product = $id_product_fee;
                $specific_price->id_cart = (int) $cart->id; // Lega il prezzo a QUESTO carrello
                $specific_price->id_shop = (int) $cart->id_shop;
                $specific_price->id_currency = (int) $cart->id_currency;
                $specific_price->id_country = (int) Context::getContext()->country->id;
                $specific_price->id_customer = (int) $cart->id_customer;
                $specific_price->price = $fee_amount; // Qui imposti l'importo della fee
                $specific_price->from_quantity = 1;
                $specific_price->reduction_type = 'amount';
                $specific_price->reduction_tax = 1;
                $specific_price->reduction = 0;
                $specific_price->from = date('Y-m-d H:i:s');
                $specific_price->to = date('Y-m-d H:i:s', strtotime('+1 hour')); // Valido 1 ora

                try {
                    $specific_price->add();
                    // Aggiungi 1 pezzo del prodotto fee al carrello
                    $cart->updateQty(1, $id_product_fee);
                } catch (Exception $e) {
                    // Gestisci l'errore (es. logga)
                    PrestaShopLogger::addLog("Errore nell'aggiunta della fee: " . $e->getMessage());
                }
            }
        }
    }
}