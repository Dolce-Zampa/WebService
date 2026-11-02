<?php
/**
 * 2026 DolceZampa
 *
 * Internal checkout endpoint for Packlink-provided InPost pickup points.
 * The only carrier integration used here is Packlink's LocationService.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\Entities\CartCarrierDropOffMapping;
use Packlink\PrestaShop\Classes\Utility\CachingUtility;
use Packlink\PrestaShop\Classes\Utility\CarrierUtility;
use Packlink\PrestaShop\Classes\Utility\InPostCheckoutUtility;
use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;

class PacklinkInpostlocationsModuleFrontController extends ModuleFrontController
{
    /**
     * @var \Carrier
     */
    protected $carrier;

    /**
     * @var string
     */
    protected $methodId;

    public function __construct()
    {
        parent::__construct();

        Bootstrap::init();
    }

    /**
     * Supports only the checkout's POST requests:
     * - getLocations
     * - getSelection
     * - selectLocation with a pointId
     */
    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            PacklinkPrestaShopUtility::die400();
        }

        if (!InPostCheckoutUtility::isValidCsrfToken($this->context, \Tools::getValue('token'))
            || !InPostCheckoutUtility::isCurrentCartOwnedByContext($this->context)
        ) {
            PacklinkPrestaShopUtility::die403();
        }

        $input = json_decode(\Tools::getValue('plPostData'), true);
        if (!is_array($input) || empty($input['method'])) {
            PacklinkPrestaShopUtility::die400();
        }

        if (!$this->initializeCurrentPacklinkInPostCarrier()) {
            // Do not call Packlink if the selected carrier is not explicitly
            // configured as the Packlink-provided InPost service.
            PacklinkPrestaShopUtility::die400();
        }

        switch ($input['method']) {
            case 'getLocations':
                PacklinkPrestaShopUtility::dieJson($this->getLocations());
                break;

            case 'getSelection':
                PacklinkPrestaShopUtility::dieJson(array('dropOff' => $this->getValidatedSelection()));
                break;

            case 'selectLocation':
                if (!isset($input['pointId']) || !is_string($input['pointId']) || $input['pointId'] === '') {
                    PacklinkPrestaShopUtility::die400();
                }

                $dropOff = $this->findLocation($input['pointId']);
                if ($dropOff === null) {
                    PacklinkPrestaShopUtility::die400();
                }

                $this->saveSelection($dropOff);
                PacklinkPrestaShopUtility::dieJson(array('dropOff' => $dropOff));
                break;

            default:
                PacklinkPrestaShopUtility::die400();
        }
    }

    /**
     * Derives the carrier and method only from the current server-side cart.
     *
     * @return bool
     */
    protected function initializeCurrentPacklinkInPostCarrier()
    {
        $carrierId = (int)$this->context->cart->id_carrier;
        if ($carrierId <= 0) {
            return false;
        }

        $carrier = new \Carrier($carrierId);
        $methodId = CarrierUtility::getInPostMethodForCarrier($carrier);
        if ($methodId === null) {
            return false;
        }

        $this->carrier = $carrier;
        $this->methodId = $methodId;

        return true;
    }

    /**
     * Gets Packlink locations for the delivery address and cart packages.
     *
     * @return array
     */
    protected function getLocations()
    {
        $address = new \Address((int)$this->context->cart->id_address_delivery);
        if (!\Validate::isLoadedObject($address)) {
            return array();
        }

        $country = new \Country((int)$address->id_country);
        if (!\Validate::isLoadedObject($country) || $address->postcode === '') {
            return array();
        }

        /** @var \Packlink\BusinessLogic\Location\LocationService $locationService */
        $locationService = ServiceRegister::getService(LocationService::CLASS_NAME);

        try {
            return $locationService->getLocations(
                $this->methodId,
                \Tools::strtoupper($country->iso_code),
                $address->postcode,
                $this->getCartPackages()
            );
        } catch (\Exception $exception) {
            return array();
        }
    }

    /**
     * Re-fetches Packlink points and returns a point only when it belongs to
     * the current cart, address, carrier and method context.
     *
     * @param string $pointId
     *
     * @return array|null
     */
    protected function findLocation($pointId)
    {
        foreach ($this->getLocations() as $location) {
            if (is_array($location) && isset($location['id']) && (string)$location['id'] === $pointId) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Validates the stored selection against the live Packlink result so a
     * carrier, address, postcode or package change invalidates stale points.
     *
     * @return array|null
     */
    protected function getValidatedSelection()
    {
        $mapping = $this->getMapping();
        if ($mapping === null) {
            return null;
        }

        $dropOff = $mapping->getDropOff();
        $pointId = is_array($dropOff) && isset($dropOff['id']) ? (string)$dropOff['id'] : '';
        $validatedDropOff = $pointId === '' ? null : $this->findLocation($pointId);
        if ($validatedDropOff !== null) {
            return $validatedDropOff;
        }

        RepositoryRegistry::getRepository(CartCarrierDropOffMapping::getClassName())->delete($mapping);

        return null;
    }

    /**
     * Saves the server-verified Packlink response using the native mapping
     * entity, preserving compatibility with Packlink's order-validation hook.
     *
     * @param array $dropOff
     */
    protected function saveSelection(array $dropOff)
    {
        $repository = RepositoryRegistry::getRepository(CartCarrierDropOffMapping::getClassName());
        $mapping = $this->getMapping();

        if ($mapping === null) {
            $mapping = new CartCarrierDropOffMapping();
            $mapping->setCartId((string)$this->context->cart->id);
            // This field is named carrierReferenceId in Packlink's entity, but
            // native checkout code stores the live cart carrier ID in it.
            $mapping->setCarrierReferenceId((string)$this->carrier->id);
            $mapping->setDropOff($dropOff);
            $repository->save($mapping);

            return;
        }

        $mapping->setDropOff($dropOff);
        $repository->update($mapping);
    }

    /**
     * @return CartCarrierDropOffMapping|null
     */
    protected function getMapping()
    {
        $query = new QueryFilter();
        $query->where('cartId', '=', (string)$this->context->cart->id)
            ->where('carrierReferenceId', '=', (string)$this->carrier->id);

        return RepositoryRegistry::getRepository(CartCarrierDropOffMapping::getClassName())->selectOne($query);
    }

    /**
     * @return \Packlink\BusinessLogic\Http\DTO\Package[]
     */
    protected function getCartPackages()
    {
        $shippingProducts = array();
        foreach ($this->context->cart->getProducts() as $product) {
            if (empty($product['is_virtual'])) {
                $shippingProducts[] = $product;
            }
        }

        return CachingUtility::getPackages($shippingProducts);
    }
}
