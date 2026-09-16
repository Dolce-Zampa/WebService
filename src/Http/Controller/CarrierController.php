<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Carrier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CarrierController extends Controller
{
    private Carrier $carrierService;

    public function __construct(Carrier $carrierService)
    {
        $this->carrierService = $carrierService;
    }

    /**
     * Get list of all active carriers
     * GET /api/carriers
     */
    public function carrierList(Request $request, Response $response): Response
    {
        try {
            $carriers = $this->carrierService->carriersList();
            
            return response($carriers->toArray());
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'error' => 'Failed to retrieve carriers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get carrier details by ID
     * GET /api/carriers/{id}
     */
    public function getCarrier(Request $request, Response $response, array $args): Response
    {
        $carrierId = (int) $args['id_carrier'];

        if ($carrierId <= 0) {
            return response([
                'success' => false,
                'error' => 'Invalid carrier ID'
            ], 400);
        }

        try {
            $carrier = $this->carrierService->getCarrierDetail($carrierId);

            if ($carrier === null) {
                return response([
                    'success' => false,
                    'error' => 'Carrier not found'
                ], 404);
            }

            return response([
                'success' => true,
                'data' => $carrier->toArray()
            ]);
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'error' => 'Failed to retrieve carrier: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available carriers for a cart
     * GET /api/carriers/available?id_cart={id_cart}&id_zone={id_zone}
     */
    public function availableCarriers(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $idCart = isset($queryParams['id_cart']) ? (int) $queryParams['id_cart'] : null;
        $idZone = isset($queryParams['id_zone']) ? (int) $queryParams['id_zone'] : null;

        if ($idCart === null || $idCart <= 0) {
            return response([
                'success' => false,
                'error' => 'id_cart query parameter is required'
            ], 400);
        }

        try {
            $carriers = $this->carrierService->getAvailableCarriers($idCart, $idZone);
            
            return response([
                'success' => true,
                'data' => $carriers->toArray()
            ]);
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'error' => 'Failed to retrieve available carriers: ' . $e->getMessage()
            ], 500);
        }
    }
}
