<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\OpenAIService;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\RedisQueue;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PrestashopProductWebhookController extends Controller
{
    use UseCache;

    private OpenAIService $openAIService;
    private Product $productService;
    private RedisQueue $queue;

    public function __construct(OpenAIService $openAIService, Product $productService, RedisQueue $queue)
    {
        $this->openAIService = $openAIService;
        $this->productService = $productService;
        $this->queue = $queue;
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
        $incomingSecret = $request->getHeaderLine('X-Webhook-Secret');
        $expectedSecret = env('WEBHOOK_SECRET', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('PrestashopProductWebhook: invalid or missing X-Webhook-Secret');
            return response(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload) || empty($payload['product_id'])) {
            return response(['error' => 'Invalid payload: product_id is required'], 400);
        }

        $productId = (int) $payload['product_id'];
        $productName = (string) ($payload['product_name'] ?? '');
        $productShortDescription = (string) ($payload['product_short_description'] ?? '');
        $textPrompt = (string) ($payload['text_prompt'] ?? '');
        $imagePrompt = (string) ($payload['image_prompt'] ?? '');
        $sourceImageUrl = (string) ($payload['source_image_url'] ?? '');

        // Only process products whose name contains the placeholder "n.d."
        if (stripos($productName, 'n.d.') === false) {
            return response([
                'received' => true,
                'processed' => false,
                'reason' => '"n.d." not found in product name',
            ], 200);
        }

        Log::info("PrestashopProductWebhook: processing product #{$productId} (name: \"{$productName}\")");

        try {
            // 1. Generate SEO texts via ChatGPT
            $cacheKey = "seo_content_{$productId}";
            if ($this->existsInCache($cacheKey)) {
                Log::info("PrestashopProductWebhook: SEO content cache hit for product #{$productId}");
                $seoContent = $this->getFromCache($cacheKey);
            } else {
                Log::info("PrestashopProductWebhook: SEO content cache miss for product #{$productId}, generating via OpenAI");
                $seoContent = $this->openAIService->generateSeoContent($productName, $textPrompt, $productShortDescription);
                $this->setToCache($cacheKey, $seoContent, 300); // Cache for 5 minutes
            }

            // 2. Queue product image generation (handled asynchronously by the worker)
            if ($this->shouldNotGenerateImage($productShortDescription) !== true) {
                $this->queue->push('product-image-jobs', [
                    'productId'               => $productId,
                    'productName'             => $seoContent['name'],
                    'imagePrompt'             => $imagePrompt,
                    'sourceImageUrl'          => $sourceImageUrl,
                    'productShortDescription' => $productShortDescription,
                ]);
                Log::info("PrestashopProductWebhook: image job queued for product #{$productId}");
            } else {
                Log::info("PrestashopProductWebhook: #NO-IMAGE# flag set for product #{$productId}, image job skipped");
            }

            // 3. Update the product content in PrestaShop (keep it inactive / unpublished)
            $this->productService->updateProduct($productId, array_merge($seoContent, ['active' => 0]));

            Log::info("PrestashopProductWebhook: product #{$productId} enriched successfully");

            return response([
                'received'   => true,
                'processed'  => true,
                'product_id' => $productId,
                'images'     => 'queued',
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
