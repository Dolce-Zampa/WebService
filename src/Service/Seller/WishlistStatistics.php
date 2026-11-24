<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Seller;

use Illuminate\Database\ConnectionInterface;

/** Current wishlist memberships, aggregated without exposing customer identities. */
final class WishlistStatistics
{
    public function __construct(private ConnectionInterface $db) {}

    public function forSeller(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $config = $this->db->table('configuration')->whereIn('name', ['PS_LANG_DEFAULT', 'PS_SHOP_DEFAULT'])
            ->orderByDesc('id_configuration')->get(['name', 'value']);
        $values = [];
        foreach ($config as $entry) {
            $values[$entry->name] ??= (int) $entry->value;
        }
        $language = max(1, $values['PS_LANG_DEFAULT'] ?? 1);
        $shop = max(1, $values['PS_SHOP_DEFAULT'] ?? 1);

        $customer = $this->db->getQueryGrammar()->wrap('wi.customer_id');
        $products = $this->db->table('dz_wishlist_item as wi')
            ->join('product as p', 'p.id_product', '=', 'wi.product_id')
            ->leftJoin('product_lang as pl', function ($join) use ($language, $shop): void {
                $join->on('pl.id_product', '=', 'p.id_product')->where('pl.id_lang', '=', $language)->where('pl.id_shop', '=', $shop);
            })
            ->where('p.id_manufacturer', $sellerId)
            ->groupBy('p.id_product', 'p.reference', 'pl.name')
            ->select('p.id_product', 'p.reference', 'pl.name')
            ->selectRaw('COUNT(DISTINCT ' . $customer . ') as customers_count');

        $totals = $this->db->query()->fromSub(clone $products, 'wishlist_products')
            ->selectRaw('COUNT(*) as products_count, COALESCE(SUM(customers_count), 0) as saves_count')->first();
        $items = $products->orderByDesc('customers_count')->orderBy('p.id_product')
            ->forPage($page, $perPage)->get()->map(static fn ($row): array => [
                'id_product' => (int) $row->id_product,
                'name' => $row->name ?: 'Prodotto #' . $row->id_product,
                'reference' => $row->reference ?: '',
                'customers_count' => (int) $row->customers_count,
            ])->all();

        return ['items' => $items, 'products_count' => (int) $totals->products_count,
            'saves_count' => (int) $totals->saves_count, 'page' => $page, 'per_page' => $perPage];
    }
}
