<?php
require_once __DIR__ . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';
require_once __DIR__ . '/../../classes/MlabFixedCarrierRates.php';

class webserviceapicarrierratesModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('GET'));
        $countryId = (int) Country::getByIso('IT');
        $zoneId = $countryId > 0 ? (int) Country::getIdZone($countryId) : 0;
        if ($zoneId <= 0) {
            throw new MlabFactoryApiException('Italian shipping zone is unavailable.', 503);
        }
        $rows = Carrier::getCarriers((int) $this->context->language->id, true, false, $zoneId, null, Carrier::ALL_CARRIERS);
        $result = array();
        foreach ($rows as $row) {
            $carrier = new Carrier((int) $row['id_carrier']);
            $row['id'] = (int) $row['id_carrier'];
            $row = array_merge($row, MlabFixedCarrierRates::prices($carrier, $zoneId, $countryId));
            $result[] = $row;
        }
        return array('carriers' => $result);
    }
}
