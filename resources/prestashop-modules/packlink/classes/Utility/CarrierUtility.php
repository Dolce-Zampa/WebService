<?php
/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
namespace Packlink\PrestaShop\Classes\Utility;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use Logeecom\Infrastructure\ORM\QueryFilter\Operators;
use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\CashOnDelivery\Interfaces\CashOnDeliveryServiceInterface;
use Packlink\BusinessLogic\Http\DTO\CashOnDelivery;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\PrestaShop\Classes\BusinessLogicServices\CarrierService;

class CarrierUtility
{
    /**
     * JSON object mapping a PrestaShop carrier reference ID to its Packlink
     * shipping-method ID. It is maintained in the back office configuration;
     * no carrier or method identifier from a checkout request is trusted.
     */
    const INPOST_CARRIER_METHODS_CONFIGURATION_KEY = 'PACKLINK_INPOST_CARRIER_METHODS';

    /**
     * Gets all carrier IDs that require drop-off.
     *
     * @var int $carrierReference
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     */
    public static function getServiceFromReferenceId($carrierReference)
    {
        $carrierService = new CarrierService();
        return $carrierService->getShippingMethodId((int)$carrierReference);
    }

    /**
     * Returns the explicit InPost-to-Packlink method mapping configured by an
     * administrator. Invalid configuration is treated as an empty mapping.
     *
     * Example value for PACKLINK_INPOST_CARRIER_METHODS:
     * {"12":"packlink-shipping-method-id"}
     *
     * @return array
     */
    public static function getInPostCarrierMethodMap()
    {
        $rawMapping = \Configuration::get(self::INPOST_CARRIER_METHODS_CONFIGURATION_KEY);
        if (!is_string($rawMapping) || $rawMapping === '') {
            return array();
        }

        $mapping = json_decode($rawMapping, true);
        if (!is_array($mapping)) {
            return array();
        }

        return self::normalizeInPostCarrierMethodMap($mapping);
    }

    /**
     * Persists the explicit InPost-to-Packlink method mapping from the module
     * configuration page. An empty map disables this checkout API safely.
     *
     * @param array $mapping
     *
     * @return bool
     */
    public static function saveInPostCarrierMethodMap(array $mapping)
    {
        return \Configuration::updateValue(
            self::INPOST_CARRIER_METHODS_CONFIGURATION_KEY,
            json_encode(self::normalizeInPostCarrierMethodMap($mapping))
        );
    }

    /**
     * Verifies an administrator-supplied reference/method pair against the
     * live Packlink carrier mapping. This prevents saving a method that is not
     * the selected Packlink destination-drop-off service.
     *
     * @param int $carrierReferenceId
     * @param string $methodId
     *
     * @return bool
     */
    public static function isValidInPostCarrierMethodPair($carrierReferenceId, $methodId)
    {
        $carrierReferenceId = (int)$carrierReferenceId;
        if ($carrierReferenceId <= 0 || !is_scalar($methodId) || trim((string)$methodId) === '') {
            return false;
        }

        $carrier = \Carrier::getCarrierByReference($carrierReferenceId);
        if (!\Validate::isLoadedObject($carrier) || $carrier->deleted) {
            return false;
        }

        $mappedMethodId = self::getServiceFromReferenceId($carrierReferenceId);

        return $mappedMethodId !== null
            && (string)$mappedMethodId === (string)$methodId
            && self::isDropOff($carrierReferenceId);
    }

    /**
     * Returns the configured InPost services keyed by the live PrestaShop
     * carrier ID. The back-office configuration deliberately uses the stable
     * carrier reference ID; checkout radio inputs, however, expose the live
     * carrier ID. Translating here keeps both representations correct after a
     * carrier is recreated or updated by PrestaShop.
     *
     * @return array
     */
    public static function getInPostLiveCarrierMethodMap()
    {
        $configuredMethods = self::getInPostCarrierMethodMap();
        if (empty($configuredMethods)) {
            return array();
        }

        $result = array();
        foreach (self::getDropOffCarrierReferenceIds() as $carrierId => $methodId) {
            $carrier = new \Carrier((int)$carrierId);
            if (!\Validate::isLoadedObject($carrier) || $carrier->deleted) {
                continue;
            }

            $referenceId = (string)(int)$carrier->id_reference;
            if (!isset($configuredMethods[$referenceId])
                || (string)$configuredMethods[$referenceId] !== (string)$methodId
                || !self::isValidInPostCarrierMethodPair($referenceId, $methodId)
            ) {
                continue;
            }

            $result[(string)(int)$carrier->id] = (string)$methodId;
        }

        return $result;
    }

    /**
     * Lists active Packlink destination-drop-off carriers to guide the module
     * administrator when choosing the InPost-provided service.
     *
     * @return array
     */
    public static function getAvailableDropOffCarrierMappings()
    {
        $result = array();
        foreach (self::getDropOffCarrierReferenceIds() as $carrierId => $methodId) {
            $carrier = new \Carrier((int)$carrierId);
            if (!\Validate::isLoadedObject($carrier) || $carrier->deleted) {
                continue;
            }

            $result[] = array(
                'carrierName' => $carrier->name,
                'carrierReferenceId' => (string)$carrier->id_reference,
                'methodId' => (string)$methodId,
            );
        }

        return $result;
    }

    /**
     * Normalizes a mapping read from or written to PrestaShop configuration.
     *
     * @param array $mapping
     *
     * @return array
     */
    private static function normalizeInPostCarrierMethodMap(array $mapping)
    {
        $result = array();
        foreach ($mapping as $carrierReferenceId => $methodId) {
            if ((string)(int)$carrierReferenceId !== (string)$carrierReferenceId
                || (int)$carrierReferenceId <= 0
                || !is_scalar($methodId)
                || trim((string)$methodId) === ''
            ) {
                continue;
            }

            $result[(string)(int)$carrierReferenceId] = (string)$methodId;
        }

        return $result;
    }

    /**
     * Returns the configured Packlink method for a live PrestaShop carrier.
     * The persisted explicit mapping must agree with Packlink's own carrier
     * mapping, preventing configuration drift from selecting another service.
     *
     * @param \Carrier $carrier
     *
     * @return string|null
     */
    public static function getInPostMethodForCarrier(\Carrier $carrier)
    {
        if (!\Validate::isLoadedObject($carrier) || (int)$carrier->id_reference <= 0) {
            return null;
        }

        $referenceId = (string)(int)$carrier->id_reference;
        $configuredMethods = self::getInPostCarrierMethodMap();
        if (empty($configuredMethods[$referenceId])) {
            return null;
        }

        $mappedMethodId = self::getServiceFromReferenceId((int)$carrier->id_reference);
        if ($mappedMethodId === null
            || (string)$mappedMethodId !== (string)$configuredMethods[$referenceId]
        ) {
            return null;
        }

        return (string)$mappedMethodId;
    }
    /**
     * Gets all carrier IDs that require drop-off.
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     */
    public static function getDropOffCarrierReferenceIds()
    {
        $repository = RepositoryRegistry::getRepository(ShippingMethod::getClassName());
        $query = new QueryFilter();
        $query->where('enabled', Operators::EQUALS, true)
            ->where('destinationDropOff', Operators::EQUALS, true);

        $methods = $repository->select($query);

        $result = array();
        $service = new CarrierService();

        /** @var ShippingMethod $method */
        foreach ($methods as $method) {
            $carrierReferenceId = $service->getCarrierReferenceId($method->getId());
            if ($carrierReferenceId) {
                $carrier = \Carrier::getCarrierByReference($carrierReferenceId);
                if (\Validate::isLoadedObject($carrier)) {
                    $result[$carrier->id] = $method->getId();
                }
            }
        }

        return $result;
    }

    /**
     * Gets all carrier IDs that require drop-off.
     *
     * @param float $cartTotal
     * @param CashOnDelivery $cod
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     */
    public static function getCashOnDeliveryReferenceIds($cartTotal, $cod)
    {
        $repository = RepositoryRegistry::getRepository(ShippingMethod::getClassName());
        $query = new QueryFilter();
        $methods = $repository->select($query);

        $result = array();
        $service = new CarrierService();

        /** @var ShippingMethod $method */
        foreach ($methods as $method) {
            $carrierReferenceId = $service->getCarrierReferenceId($method->getId());

            $services = $method->getShippingServices();

            foreach ($services as $shippingService) {
                if($shippingService->cashOnDeliveryConfig &&  $shippingService->cashOnDeliveryConfig->offered) {
                    if($cod->account->getCashOnDeliveryFee() !== null) {
                        $result[$carrierReferenceId] = $cod->account->getCashOnDeliveryFee();
                        break;
                    }

                    $result[$carrierReferenceId] = self::calculateFee(
                        $cartTotal, $shippingService->cashOnDeliveryConfig->applyPercentageCashOnDelivery,
                        $shippingService->cashOnDeliveryConfig->maxCashOnDelivery);
                    break;
                }
            }

        }

        return $result;
    }

    /**
     * Checks whether carrier with provided reference is a drop-off or not.
     *
     * @param int $carrierReference
     *
     * @return bool
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public static function isDropOff($carrierReference)
    {
        $service = new CarrierService();
        $id = $service->getShippingMethodId($carrierReference);

        if ($id === null) {
            return false;
        }

        $repository = RepositoryRegistry::getRepository(ShippingMethod::getClassName());
        $filter = new QueryFilter();
        $filter->where('id', Operators::EQUALS, $id);
        /** @var ShippingMethod $method */
        $method = $repository->selectOne($filter);

        return $method && $method->isDestinationDropOff();
    }


    public static function getCashOnDeliveryConfig()
    {
        /** @var CashOnDeliveryServiceInterface $codService */
        $codService = ServiceRegister::getService(CashOnDeliveryServiceInterface::CLASS_NAME);
        try {
            return $codService->getCashOnDeliveryConfig();
        } catch (QueryFilterInvalidParamException $e) {
            return null;
        }
    }

    /**
     * Calculate COD surcharge fee if it is not set in the configuration than use from api.
     *
     * @param float $orderTotal Total order amount
     * @param float $percentage Percentage fee
     * @param float $minFee Minimum fee
     *
     * @return float COD surcharge
     * @throws QueryFilterInvalidParamException
     */
    private static function calculateFee($orderTotal, $percentage, $minFee)
    {
        $calculated = round($orderTotal * ($percentage / 100), 2);

        if ($calculated < $minFee) {
            return $minFee;
        }

        return $calculated;
    }
}
