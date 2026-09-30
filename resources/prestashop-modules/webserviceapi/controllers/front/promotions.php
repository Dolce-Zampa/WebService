<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapipromotionsModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('GET'));

        $page = $this->getPositiveInteger('page', 1, 1000000);
        $limit = $this->getPositiveInteger('limit', 20, 100);
        $orderBy = Tools::getValue('order_by', 'id_product');
        $orderWay = Tools::getValue('order_way', 'DESC');
        if (!is_string($orderBy) || !in_array($orderBy, array('id_product', 'name', 'date_add', 'date_upd', 'price'), true)) {
            throw new MlabFactoryApiException('Invalid order_by.', 400);
        }
        if (!is_string($orderWay) || !in_array(strtoupper($orderWay), array('ASC', 'DESC'), true)) {
            throw new MlabFactoryApiException('Invalid order_way.', 400);
        }
        $orderWay = strtoupper($orderWay);
        $idLang = (int) $this->context->language->id;

        $total = Product::getPricesDrop($idLang, 1, $limit, true, $orderBy, $orderWay, false, false, $this->context);
        if ($total === false) {
            throw new MlabFactoryApiException('Unable to retrieve promotions.', 500);
        }
        $products = array();
        if ($total > 0 && ($page - 1) * $limit < $total) {
            $products = Product::getPricesDrop($idLang, $page, $limit, false, $orderBy, $orderWay, false, false, $this->context);
            if ($products === false) {
                throw new MlabFactoryApiException('Unable to retrieve promotions.', 500);
            }
        }

        return array(
            'products' => $products,
            'pagination' => array(
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'pages' => (int) ceil($total / $limit),
            ),
            'id_lang' => $idLang,
            'id_currency' => (int) $this->context->currency->id,
            'id_shop' => (int) $this->context->shop->id,
        );
    }

    protected function getPositiveInteger($name, $default, $maximum)
    {
        $value = Tools::getValue($name, $default);
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value) || $value > $maximum) {
            throw new MlabFactoryApiException($name . ' must be an integer between 1 and ' . $maximum . '.', 400);
        }

        return (int) $value;
    }
}
