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
        $productShortDescription = (string) ($payload['product_short_description'] ?? '');
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
            $seoContent = $this->openAIService->generateSeoContent($productName, $textPrompt, $productShortDescription);

            // 2. Generate product images via AI (best-effort; failures are logged but non-fatal)
            // Always produce at least 5 images: 1 main + 4 detail/zoom/lifestyle shots.
            $imageUrls = [];
            try {
                if ($this->shouldNotGenerateImage($productShortDescription) !== true) {
                    Log::info("PrestashopProductWebhook: image generation triggered for product #{$productId} based on short description");

                    if (!empty($sourceImageUrl)) {
                        // Edit the existing source image as the primary shot
                        try {
                            $editedUrl = $this->openAIService->editProductImage($sourceImageUrl, $seoContent['name'], $imagePrompt);
                            $imageUrls[] = $editedUrl;
                        } catch (\Exception $editEx) {
                            Log::warning("PrestashopProductWebhook: source image edit failed for product #{$productId}: " . $editEx->getMessage());
                        }

                        // Generate 4 additional detail/zoom/lifestyle images
                        $additionalUrls = $this->openAIService->generateProductImages($seoContent['name'], 4, $imagePrompt);
                        $imageUrls = array_merge($imageUrls, $additionalUrls);
                    } else {
                        // No source image – generate 5 varied images from scratch
                        Log::warning("PrestashopProductWebhook: source image missing for product #{$productId}, generating 5 images");
                        $imageUrls = $this->openAIService->generateProductImages($seoContent['name'], 5, $imagePrompt);
                    }
                }
            } catch (\Exception $imageEx) {
                Log::warning("PrestashopProductWebhook: image processing skipped for product #{$productId}: " . $imageEx->getMessage());
            }

            // 3. Update the product content in PrestaShop (keep it inactive / unpublished)
            $this->productService->updateProduct($productId, array_merge($seoContent, ['active' => 0]));

            // 4. Upload all generated images
            foreach ($imageUrls as $idx => $imageUrl) {
                try {
                    $this->productService->uploadProductImage($productId, $imageUrl);
                } catch (\Exception $uploadEx) {
                    Log::warning("PrestashopProductWebhook: image upload #{$idx} failed for product #{$productId}: " . $uploadEx->getMessage());
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

    /**
     * find into a short description some key configurations
     * 
     * @param string $productShortDescription
     * @return bool
     */
    private function shouldNotGenerateImage($productShortDescription): bool
    {
        $key = "#NO-IMAGE#";
        return stripos($productShortDescription, $key) !== false;
    }
}
