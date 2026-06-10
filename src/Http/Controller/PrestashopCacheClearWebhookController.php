<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestashopCacheClearWebhookController extends Controller
{
    use UseCache;

    /**
     * Handles the PrestaShop product-saved cache-clear webhook.
     *
     * Fired for every product add/update so that the product-related API
     * cache is always invalidated after a back-office save, regardless of
     * whether the AI enrichment webhook is also triggered.
     *
     * Expected header: X-Webhook-Secret: <secret>
     * Optional JSON body: { "product_id": <int> }
     */
    public function handleWebhook(Request $request, Response $response, array $args): Response
    {
        $incomingSecret = $request->getHeaderLine('X-Webhook-Secret');
        $expectedSecret = env('WEBHOOK_SECRET', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('PrestashopCacheClearWebhook: invalid or missing X-Webhook-Secret');
            return response(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $productId = isset($payload['product_id']) ? (int) $payload['product_id'] : null;

        Log::info('PrestashopCacheClearWebhook: clearing product cache' . ($productId ? " for product #{$productId}" : ''));

        foreach (['products', 'product-detail', 'products,promotions'] as $tag) {
            $this->tags = [$tag];
            $this->flushTag();
        }

        return response([
            'received' => true,
            'cache_cleared' => true,
            'product_id' => $productId,
        ], 200);
    }
}
