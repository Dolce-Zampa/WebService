<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** One private list per authenticated customer; the token grants read-only access. */
final class WishlistController
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($customer = $this->customerId($request)) === null) {
            return $this->respond(['message' => 'Unauthorized'], 401);
        }
        return $this->respond(['wishlist' => $this->serialize($this->getOrCreate($customer), true)]);
    }

    public function rename(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($customer = $this->customerId($request)) === null) {
            return $this->respond(['message' => 'Unauthorized'], 401);
        }
        $body = $request->getParsedBody();
        $name = is_array($body) && is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || mb_strlen($name) > 128) {
            return $this->respond(['message' => 'Name must contain between 1 and 128 characters.'], 422);
        }
        $this->getOrCreate($customer);
        $this->db->table('dz_wishlist')->where('customer_id', $customer)->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->respond(['wishlist' => $this->serialize($this->getOrCreate($customer), true)]);
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($customer = $this->customerId($request)) === null) {
            return $this->respond(['message' => 'Unauthorized'], 401);
        }
        $body = $request->getParsedBody();
        $ids = $this->productIds($body);
        if ($ids === null) {
            return $this->respond(['message' => 'Invalid product or combination identifier.'], 422);
        }
        [$product, $attribute] = $ids;
        $row = $this->db->table('product')->where('id_product', $product)->first();
        $shop = $this->shopId();
        $shopProduct = $this->db->table('product_shop')->where('id_product', $product)->where('id_shop', $shop)->first();
        if (!$row || !$shopProduct || empty($shopProduct->active) || empty($shopProduct->available_for_order)) {
            return $this->respond(['message' => 'Product not available.'], 404);
        }
        if ($attribute > 0 && !$this->db->table('product_attribute')->where('id_product', $product)->where('id_product_attribute', $attribute)->exists()) {
            return $this->respond(['message' => 'Product combination not found.'], 404);
        }
        $this->getOrCreate($customer);
        $this->db->table('dz_wishlist_item')->insertOrIgnore([
            'customer_id' => $customer, 'product_id' => $product,
            'product_attribute_id' => $attribute, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->respond(['wishlist' => $this->serialize($this->getOrCreate($customer), true)]);
    }

    public function remove(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($customer = $this->customerId($request)) === null) {
            return $this->respond(['message' => 'Unauthorized'], 401);
        }
        $ids = $this->productIds($request->getParsedBody());
        if ($ids === null) {
            return $this->respond(['message' => 'Invalid product or combination identifier.'], 422);
        }
        $this->getOrCreate($customer);
        $this->db->table('dz_wishlist_item')->where('customer_id', $customer)
            ->where('product_id', $ids[0])->where('product_attribute_id', $ids[1])->delete();
        return $this->respond(['wishlist' => $this->serialize($this->getOrCreate($customer), true)]);
    }

    public function shared(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        if (!is_string($token)) {
            return $this->respond(['message' => 'Wishlist not found.'], 404);
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $list = $this->db->table('dz_wishlist')->where('share_token', $token)->first();
            if (!$list || !hash_equals($list->share_token, $token)) $list = null;
        } else {
            $bytes = preg_match('/\A[A-Za-z0-9_-]{22}\z/', $token)
                ? base64_decode(strtr($token, '-_', '+/') . '==', true) : false;
            $canonical = $bytes === false ? '' : rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $prefix = $bytes !== false && strlen($bytes) === 16 && hash_equals($canonical, $token) ? bin2hex($bytes) : null;
            $matches = $prefix === null ? [] : $this->db->table('dz_wishlist')->where('share_token', 'like', $prefix . '%')->limit(2)->get()->all();
            $list = count($matches) === 1 && hash_equals(substr($matches[0]->share_token, 0, 32), $prefix) ? $matches[0] : null;
        }
        if (!$list) {
            return $this->respond(['message' => 'Wishlist not found.'], 404);
        }
        return $this->respond(['wishlist' => $this->serialize($list, false)]);
    }

    private function customerId(ServerRequestInterface $request): ?int
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '') {
            return null;
        }
        $id = $this->db->table('customer')->where('sub', $sub)->value('id_customer');
        return $id ? (int) $id : null;
    }

    private function getOrCreate(int $customer): object
    {
        $list = $this->db->table('dz_wishlist')->where('customer_id', $customer)->first();
        if ($list) {
            return $list;
        }
        return $this->db->transaction(function () use ($customer) {
            // Import the customer's existing default PrestaShop list once, if present.
            $legacy = null;
            if ($this->db->getSchemaBuilder()->hasTable('wishlist') && $this->db->getSchemaBuilder()->hasTable('wishlist_product')) {
                $legacy = $this->db->table('wishlist')->where('id_customer', $customer)
                    ->orderByDesc('default')->orderBy('id_wishlist')->first();
            }
            $now = date('Y-m-d H:i:s');
            $name = $legacy && is_string($legacy->name) && trim($legacy->name) !== ''
                ? mb_substr(trim($legacy->name), 0, 128) : 'Lista desideri';
            $inserted = $this->db->table('dz_wishlist')->insertOrIgnore([
                'customer_id' => $customer, 'name' => $name,
                'share_token' => bin2hex(random_bytes(32)), 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted && $legacy) {
                $items = $this->db->table('wishlist_product')->where('id_wishlist', $legacy->id_wishlist)->get();
                foreach ($items as $item) {
                    $this->db->table('dz_wishlist_item')->insertOrIgnore([
                        'customer_id' => $customer, 'product_id' => (int) $item->id_product,
                        'product_attribute_id' => max(0, (int) $item->id_product_attribute), 'created_at' => $now,
                    ]);
                }
            }
            $list = $this->db->table('dz_wishlist')->where('customer_id', $customer)->first();
            if (!$list) {
                throw new \RuntimeException('Unable to create wishlist.');
            }
            return $list;
        });
    }

    private function productIds(mixed $body): ?array
    {
        if (!is_array($body)) return null;
        $product = $body['id_product'] ?? null;
        $attribute = $body['id_product_attribute'] ?? 0;
        if (!is_int($product) || $product < 1 || !is_int($attribute) || $attribute < 0) return null;
        return [$product, $attribute];
    }

    private function serialize(object $list, bool $owner): array
    {
        $shop = $this->shopId();
        $group = (int) $this->db->table('shop')->where('id_shop', $shop)->value('id_shop_group');
        $defaultOutOfStock = (int) $this->db->table('configuration')->where('name', 'PS_ORDER_OUT_OF_STOCK')->orderByDesc('id_configuration')->value('value');
        $items = $this->db->table('dz_wishlist_item as wi')
            ->leftJoin('product as p', 'p.id_product', '=', 'wi.product_id')
            ->leftJoin('product_shop as ps', function ($join) use ($shop) {
                $join->on('ps.id_product', '=', 'wi.product_id')->where('ps.id_shop', '=', $shop);
            })
            ->where('wi.customer_id', $list->customer_id)
            ->orderBy('wi.created_at')->orderBy('wi.product_id')->orderBy('wi.product_attribute_id')
            ->get(['wi.product_id', 'wi.product_attribute_id', 'wi.created_at', 'p.id_product as exists_id', 'ps.active', 'ps.available_for_order']);
        $result = ['name' => $list->name, 'products' => []];
        if ($owner) {
            $result['share_token'] = $list->share_token;
            $prefix = substr($list->share_token, 0, 32);
            // Existing 256-bit links stay valid; a unique 128-bit prefix provides a shorter alias.
            if (preg_match('/\A[a-f0-9]{32}\z/', $prefix)
                && $this->db->table('dz_wishlist')->where('share_token', 'like', $prefix . '%')->limit(2)->count() === 1) {
                $result['short_token'] = rtrim(strtr(base64_encode(hex2bin($prefix)), '+/', '-_'), '=');
            }
        }
        foreach ($items as $item) {
            $exists = $item->exists_id !== null;
            $combinationExists = !$item->product_attribute_id || $this->db->table('product_attribute')
                ->where('id_product', $item->product_id)->where('id_product_attribute', $item->product_attribute_id)->exists();
            $stock = $this->db->table('stock_available')->where('id_product', $item->product_id)
                ->where('id_product_attribute', $item->product_attribute_id)
                ->where(function ($query) use ($shop, $group) {
                    $query->where('id_shop', $shop)->orWhere(function ($query) use ($group) {
                        $query->where('id_shop', 0)->where('id_shop_group', $group);
                    });
                })->orderByDesc('id_shop')->first();
            $outOfStockAllowed = $stock && ((int) $stock->out_of_stock === 1
                || ((int) $stock->out_of_stock === 2 && $defaultOutOfStock === 1));
            $result['products'][] = [
                'id_product' => (int) $item->product_id,
                'id_product_attribute' => (int) $item->product_attribute_id,
                'available' => $exists && (bool) $item->active && (bool) $item->available_for_order && $combinationExists
                    && $stock !== null && ((int) $stock->quantity > 0 || $outOfStockAllowed),
                'stock_quantity' => $stock ? (int) $stock->quantity : null,
                'date_add' => $item->created_at,
            ];
        }
        return $result;
    }

    private function shopId(): int
    {
        $id = (int) $this->db->table('configuration')->where('name', 'PS_SHOP_DEFAULT')->orderByDesc('id_configuration')->value('value');
        return $id > 0 ? $id : 1;
    }

    private function respond(array $body, int $status = 200): ResponseInterface
    {
        return response($body, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
