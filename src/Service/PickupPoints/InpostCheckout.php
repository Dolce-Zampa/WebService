<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PickupPoints;

use Illuminate\Database\ConnectionInterface;

final class InpostCheckout
{
    public function __construct(private readonly ConnectionInterface $db, private readonly array $carrierIds, private readonly int $maxAgeHours = 72) {}

    public static function parseCarrierIds(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            if (!ctype_digit($entry) || (int) $entry < 1) throw new \InvalidArgumentException('Invalid InPost carrier configuration.');
            $ids[] = (int) $entry;
        }
        return array_values(array_unique($ids));
    }

    public function carrierIds(): array { return $this->carrierIds; }

    /** Replace the client address with the current server-verified point. */
    public function prepare(array $payload, int $carrierId): array
    {
        $id = $payload['inpost_point_id'] ?? null;
        if (!in_array($carrierId, $this->carrierIds, true)) {
            if ($id !== null) throw new \InvalidArgumentException('Carrier is not configured for InPost.');
            return $payload;
        }
        $country = $payload['delivery_address']['country'] ?? null;
        if (!is_string($country) || strtoupper(trim($country)) !== 'IT') throw new \InvalidArgumentException('La consegna InPost è disponibile solo in Italia.');
        if (!is_string($id) || $id === '' || strlen($id) > 100) throw new \InvalidArgumentException('Seleziona un punto InPost valido.');
        $sync = $this->db->table('inpost_sync')->where('id', 1)->first();
        $last = isset($sync->last_success_at) ? strtotime($sync->last_success_at . ' UTC') : false;
        if ($last === false || $last < time() - max(1, $this->maxAgeHours) * 3600) throw new \RuntimeException('InPost catalog unavailable or outdated.');
        $point = $this->db->table('inpost_points')->where('point_id', $id)->first();
        if (!$point) throw new \InvalidArgumentException('Il punto InPost selezionato non è più disponibile.');
        $payload['delivery_address'] = [
            'alias' => 'InPost',
            'address1' => $point->address,
            'address2' => 'InPost ' . $point->point_id,
            'city' => $point->city,
            'postcode' => $point->postal_code,
            'state' => $point->province ?? '',
            'country' => 'IT',
        ];
        return $payload;
    }
}
