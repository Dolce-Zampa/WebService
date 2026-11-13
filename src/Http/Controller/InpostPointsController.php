<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InpostPointsController
{
    public function __construct(private readonly ConnectionInterface $db, private readonly int $maxAgeHours = 72, private readonly array $carrierIds = []) {}

    public function config(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return response(['carrier_ids' => $this->carrierIds]);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $request->getQueryParams();
        $postalCode = $input['postal_code'] ?? '';
        $city = $input['city'] ?? '';
        $page = $input['page'] ?? '1';
        $limit = $input['limit'] ?? '20';
        if (!is_string($postalCode) || !is_string($city) || strlen($city) > 150
            || ($postalCode !== '' && !preg_match('/^[0-9]{5}$/D', $postalCode))
            || (trim($city) === '' && $postalCode === '')
            || !is_scalar($page) || !ctype_digit((string) $page) || (int) $page < 1 || (int) $page > 10000
            || !is_scalar($limit) || !ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100) {
            return response(['error' => 'Provide a valid postal_code or city, page and limit.'], 422);
        }
        $metadata = $this->db->table('inpost_sync')->where('id', 1)->first();
        $lastSync = $metadata->last_success_at ?? null;
        $time = $lastSync ? strtotime($lastSync . ' UTC') : false;
        if ($time === false || $time < time() - max(1, $this->maxAgeHours) * 3600) {
            return response(['error' => 'InPost catalog unavailable or outdated.', 'last_sync_at' => $lastSync ? str_replace(' ', 'T', $lastSync) . 'Z' : null], 503);
        }
        $query = $this->db->table('inpost_points');
        if ($postalCode !== '') {
            $query->where('postal_code', $postalCode);
        }
        if (trim($city) !== '') {
            $query->where('city', trim($city));
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('city')->orderBy('address')->orderBy('point_id')
            ->offset(((int) $page - 1) * (int) $limit)->limit((int) $limit)->get()->toArray();
        foreach ($items as $item) {
            $item->opening_hours = $item->opening_hours === null ? null : json_decode($item->opening_hours, true);
        }
        return response(['items' => $items, 'page' => (int) $page, 'limit' => (int) $limit, 'total' => $total,
            'last_sync_at' => str_replace(' ', 'T', $lastSync) . 'Z']);
    }
}
