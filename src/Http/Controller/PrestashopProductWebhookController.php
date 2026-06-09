<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\OpenAIService;
use PS\Webservice\Service\PS\Product;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestashopProductWebhookController extends Controller
{
    private OpenAIService $openAIService;
    private Product $productService;

    public function __construct(OpenAIService $openAIService, Product $productService)
    {
        $this->openAIService  = $openAIService;
        $this->productService = $productService;
    }

    /**
     * Handles the PrestaShop product-saved webhook.
     *
     * Expected JSON body: { "product_id": <int>, "product_name": <string> }
     * Expected header:    X-Webhook-Secret: <secret>
     *
     * When the product name contains "n.d." the handler:
     *  1. Generates SEO content (name, descriptions, meta) via ChatGPT.
     *  2. Generates a product image via DALL-E.
     *  3. Updates the product in PrestaShop (kept inactive).
     *  4. Uploads the generated image to the product.
     */
    public function handleWebhook(Request $request, Response $response, array $args): Response
    {
        // Authenticate the incoming webhook with the shared secret
        $incomingSecret  = $request->getHeaderLine('X-Webhook-Secret');
        $expectedSecret  = env('WEBHOOK_SECRET', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('PrestashopProductWebhook: invalid or missing X-Webhook-Secret');
            return response(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload) || empty($payload['product_id'])) {
            return response(['error' => 'Invalid payload: product_id is required'], 400);
        }

        $productId   = (int) $payload['product_id'];
        $productName = (string) ($payload['product_name'] ?? '');
        $textPrompt     = (string) ($payload['text_prompt'] ?? '');
        $imagePrompt    = (string) ($payload['image_prompt'] ?? '');
        $sourceImageUrl = (string) ($payload['source_image_url'] ?? '');

        // Only process products whose name contains the placeholder "n.d."
        if (stripos($productName, 'n.d.') === false) {
            return response([
                'received'  => true,
                'processed' => false,
                'reason'    => '"n.d." not found in product name',
            ], 200);
        }

        Log::info("PrestashopProductWebhook: processing product #{$productId} (name: \"{$productName}\")");

        try {
            // 1. Generate SEO texts via ChatGPT
            $seoContent = $this->openAIService->generateSeoContent($productName, $textPrompt);

            // 2. Edit the existing product image via AI (best-effort; failures are logged but non-fatal)
            $imageUrl = null;
            try {
                if (!empty($sourceImageUrl)) {
                    $imageUrl = $this->openAIService->editProductImage($sourceImageUrl, $seoContent['name'], $imagePrompt);
                } else {
                    $imageUrl = $this->openAIService->generateProductImage($seoContent['name'], $imagePrompt);
                    Log::warning("PrestashopProductWebhook: source image missing for product #{$productId}, fallback to image generation");
                }
            } catch (\Exception $imageEx) {
                Log::warning("PrestashopProductWebhook: image processing skipped for product #{$productId}: " . $imageEx->getMessage());
            }

            // 3. Update the product content in PrestaShop (keep it inactive / unpublished)
            $this->productService->updateProduct($productId, array_merge($seoContent, ['active' => 0]));

            // 4. Upload the generated image if one was produced
            if (!empty($imageUrl)) {
                try {
                    $this->productService->uploadProductImage($productId, $imageUrl);
                } catch (\Exception $uploadEx) {
                    Log::warning("PrestashopProductWebhook: image upload failed for product #{$productId}: " . $uploadEx->getMessage());
                }
            }

            Log::info("PrestashopProductWebhook: product #{$productId} enriched successfully");

            return response([
                'received'   => true,
                'processed'  => true,
                'product_id' => $productId,
            ], 200);
        } catch (\Exception $e) {
            Log::error("PrestashopProductWebhook: failed to process product #{$productId}: " . $e->getMessage());
            return response(['error' => 'Failed to process product'], 500);
        }
    }
}
