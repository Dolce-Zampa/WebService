<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Promotions;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Collection;
use PS\Webservice\Domain\Models\PS\Products\ProductPromotion;
use PS\Webservice\Domain\Models\PS\Products\ProductPromotionStat;
use PS\Webservice\Domain\Models\PS\Products\PromotionPackage;
use PS\Webservice\Service\PS\Product;

class PromotionService
{
    public function __construct(private readonly Product $productService)
    {
    }

    public function getAvailablePackages(?string $position = null): Collection
    {
        return PromotionPackage::query()
            ->where('active', 1)
            ->when($position !== null && $position !== '', function ($query) use ($position) {
                $query->where('position', $position);
            })
            ->orderBy('price')
            ->get();
    }

    public function isProductPromotable(int $sellerId, int $productId): bool
    {
        $now = Carbon::now();

        return !ProductPromotion::query()
            ->where('seller_id', $sellerId)
            ->where('product_id', $productId)
            ->where(function ($query) use ($now) {
                $query
                    ->where('status', 'pending')
                    ->orWhere(function ($activeQuery) use ($now) {
                        $activeQuery
                            ->where('status', 'active')
                            ->whereNotNull('start_date')
                            ->whereNotNull('end_date')
                            ->where('start_date', '<=', $now)
                            ->where('end_date', '>=', $now);
                    });
            })
            ->exists();
    }

    public function createPromotionRequest(int $sellerId, int $productId, int $packageId): ProductPromotion
    {
        $product = $this->productService->getProductById($productId);
        if ($product === null) {
            throw new \RuntimeException('Product not found', 404);
        }

        $productData = $product->toArray();
        if ((int) ($productData['id_manufacturer'] ?? 0) !== $sellerId) {
            throw new \RuntimeException('Forbidden', 403);
        }

        $package = PromotionPackage::query()
            ->where('id', $packageId)
            ->where('active', 1)
            ->first();

        if ($package === null) {
            throw new \RuntimeException('Promotion package not found or inactive', 404);
        }

        if (!$this->isProductPromotable($sellerId, $productId)) {
            throw new \RuntimeException('Product is not promotable', 422);
        }

        return ProductPromotion::query()->create([
            'product_id' => $productId,
            'seller_id' => $sellerId,
            'package_id' => (int) $package->id,
            'price' => (float) $package->price,
            'status' => 'pending',
        ]);
    }

    public function createCheckoutSession(
        int $sellerId,
        int $promotionId,
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): \Stripe\Checkout\Session {
        $promotion = ProductPromotion::query()->with('package')
            ->where('id', $promotionId)
            ->where('seller_id', $sellerId)
            ->first();

        if ($promotion === null) {
            throw new \RuntimeException('Promotion not found', 404);
        }

        if ($promotion->status !== 'pending') {
            throw new \RuntimeException('Promotion cannot be paid in current status', 422);
        }

        $package = $promotion->package;
        if ($package === null || (int) $package->active !== 1) {
            throw new \RuntimeException('Promotion package not available', 422);
        }

        $apiKey = (string) env('STRIPE_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('Stripe API key not configured', 500);
        }

        \Stripe\Stripe::setApiKey($apiKey);

        $session = \Stripe\Checkout\Session::create([
            'mode' => 'payment',
            'success_url' => $successUrl ?: (string) env('STRIPE_SUCCESS_URL', ''),
            'cancel_url' => $cancelUrl ?: (string) env('STRIPE_CANCEL_URL', ''),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => (int) round(((float) $promotion->price) * 100),
                    'product_data' => [
                        'name' => (string) $package->name,
                        'description' => 'Promozione prodotto #' . $promotion->product_id,
                    ],
                ],
            ]],
            'metadata' => [
                'seller_id' => (string) $promotion->seller_id,
                'product_id' => (string) $promotion->product_id,
                'promotion_package_id' => (string) $promotion->package_id,
                'promotion_id' => (string) $promotion->id,
            ],
            'client_reference_id' => (string) $promotion->id,
        ]);

        $promotion->stripe_session_id = (string) $session->id;
        $promotion->save();

        return $session;
    }

    public function isPromotionCheckoutSession(\Stripe\StripeObject $session): bool
    {
        $metadata = $session->metadata ?? null;

        return isset($metadata->promotion_id, $metadata->promotion_package_id, $metadata->seller_id, $metadata->product_id);
    }

    public function activatePromotionFromStripeSession(\Stripe\StripeObject $session): ?ProductPromotion
    {
        $metadata = $session->metadata ?? null;
        $promotionId = isset($metadata->promotion_id) ? (int) $metadata->promotion_id : 0;
        if ($promotionId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($session, $metadata, $promotionId) {
            $promotion = ProductPromotion::query()->with('package')->lockForUpdate()->find($promotionId);
            if ($promotion === null) {
                return null;
            }

            if ($promotion->status === 'active' && (string) $promotion->stripe_session_id === (string) $session->id) {
                return $promotion;
            }

            if ((int) ($metadata->seller_id ?? 0) !== (int) $promotion->seller_id
                || (int) ($metadata->product_id ?? 0) !== (int) $promotion->product_id
                || (int) ($metadata->promotion_package_id ?? 0) !== (int) $promotion->package_id
            ) {
                throw new \RuntimeException('Invalid promotion metadata received from Stripe');
            }

            $package = $promotion->package;
            if ($package === null) {
                throw new \RuntimeException('Promotion package not found');
            }

            $currency = strtolower((string) ($session->currency ?? ''));
            if ($currency !== 'eur') {
                throw new \RuntimeException('Unsupported promotion currency');
            }

            $expectedAmount = (int) round(((float) $promotion->price) * 100);
            $amountTotal = (int) ($session->amount_total ?? 0);
            if ($amountTotal !== $expectedAmount) {
                throw new \RuntimeException('Invalid promotion amount received from Stripe');
            }

            $startDate = Carbon::now();
            $endDate = (clone $startDate)->addDays((int) $package->duration_days);

            $promotion->status = 'active';
            $promotion->start_date = $startDate;
            $promotion->end_date = $endDate;
            $promotion->stripe_session_id = (string) $session->id;
            $promotion->stripe_payment_intent_id = isset($session->payment_intent) ? (string) $session->payment_intent : null;
            $promotion->save();

            return $promotion;
        });
    }

    public function getActiveSponsoredProducts(?string $position = null, int $limit = 6): Collection
    {
        $now = Carbon::now();

        $promotions = ProductPromotion::query()
            ->with('package')
            ->where('status', 'active')
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->whereHas('package', function ($query) use ($position) {
                $query->where('active', 1);
                if ($position !== null && $position !== '') {
                    $query->where('position', $position);
                }
            })
            ->get()
            ->shuffle()
            ->take(max(1, min($limit, 30)));

        $result = new Collection();
        foreach ($promotions as $promotion) {
            $product = $this->productService->getProductById((int) $promotion->product_id);
            if ($product === null) {
                continue;
            }

            $this->incrementStat((int) $promotion->id, 'impressions');

            $result->push([
                'promotion_id' => (int) $promotion->id,
                'product_id' => (int) $promotion->product_id,
                'seller_id' => (int) $promotion->seller_id,
                'position' => (string) ($promotion->package?->position ?? ''),
                'start_date' => $promotion->start_date,
                'end_date' => $promotion->end_date,
                'product' => $product->toArray(),
            ]);
        }

        return $result;
    }

    public function recordClick(int $promotionId): void
    {
        $promotion = ProductPromotion::query()->find($promotionId);
        if ($promotion === null) {
            throw new \RuntimeException('Promotion not found', 404);
        }

        $this->incrementStat($promotionId, 'clicks');
    }

    private function incrementStat(int $promotionId, string $field): void
    {
        $stats = ProductPromotionStat::query()->firstOrCreate(
            ['promotion_id' => $promotionId],
            ['impressions' => 0, 'clicks' => 0]
        );

        $stats->increment($field);
    }
}
