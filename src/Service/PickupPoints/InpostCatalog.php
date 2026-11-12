<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PickupPoints;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\ConnectionInterface;

final class InpostCatalog
{
    private const URL = 'https://api-it-local-points.easypack24.net/v1/points';
    private const PAGE_SIZE = 1000;

    public function __construct(private readonly ClientInterface $http, private readonly ConnectionInterface $db) {}

    /** Fetch a complete snapshot under a database lock; readers retain the old snapshot until commit. */
    public function sync(): int
    {
        return $this->db->transaction(function (): int {
            $metadata = $this->db->table('inpost_sync')->where('id', 1)->lockForUpdate()->first();
            if (!$metadata) {
                throw new \RuntimeException('InPost synchronization metadata is missing.');
            }

            $first = $this->fetchPage(1);
            $count = $first['count'];
            $pages = $first['total_pages'];
            if ($count < 1 || $pages !== (int) ceil($count / self::PAGE_SIZE) || $pages > 1000) {
                throw new \RuntimeException('InPost returned invalid pagination.');
            }
            $rows = [];
            $seen = [];
            for ($page = 1; $page <= $pages; $page++) {
                $result = $page === 1 ? $first : $this->fetchPage($page);
                $expected = min(self::PAGE_SIZE, $count - (($page - 1) * self::PAGE_SIZE));
                if ($result['page'] !== $page || $result['count'] !== $count || $result['total_pages'] !== $pages || count($result['items']) !== $expected) {
                    throw new \RuntimeException('InPost changed or returned an incomplete snapshot.');
                }
                foreach ($result['items'] as $point) {
                    if (!is_array($point) || !is_string($point['name'] ?? null) || trim($point['name']) === '' || isset($seen[$point['name']])) {
                        throw new \RuntimeException('InPost returned a point without a unique identifier.');
                    }
                    $seen[$point['name']] = true;
                    if (($point['status'] ?? null) !== 'Operating') {
                        continue;
                    }
                    $rows[] = $this->normalize($point);
                }
            }
            if (count($rows) === 0 || ((int) $metadata->point_count > 0 && count($rows) < (int) $metadata->point_count * 0.5)) {
                throw new \RuntimeException('InPost returned an unexpectedly small snapshot.');
            }
            // A moving paginated collection can otherwise omit records even if counts match.
            $check = $this->fetchPage(1);
            if ($check['count'] !== $count || $check['total_pages'] !== $pages || $check['items'] !== $first['items']) {
                throw new \RuntimeException('InPost snapshot changed during synchronization.');
            }
            $this->db->table('inpost_points')->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                $this->db->table('inpost_points')->insert($chunk);
            }
            $this->db->table('inpost_sync')->where('id', 1)->update([
                'point_count' => count($rows), 'last_success_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return count($rows);
        }, 1);
    }

    private function fetchPage(int $page): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request('GET', self::URL, [
                    'query' => ['status' => 'Operating', 'per_page' => self::PAGE_SIZE, 'page' => $page],
                    'timeout' => 30, 'connect_timeout' => 5, 'headers' => ['Accept' => 'application/json'],
                ]);
                break;
            } catch (ConnectException | RequestException $error) {
                $status = $error instanceof RequestException ? $error->getResponse()?->getStatusCode() : null;
                if ($attempt >= 2 || ($status !== null && $status < 500)) {
                    throw $error;
                }
                usleep(250000);
            }
        }
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_int($data['count'] ?? null) || !is_int($data['page'] ?? null)
            || !is_int($data['total_pages'] ?? null) || !is_array($data['items'] ?? null)) {
            throw new \RuntimeException('InPost returned an invalid response.');
        }
        return $data;
    }

    private function normalize(array $point): array
    {
        $address = $point['address'] ?? [];
        $details = $point['address_details'] ?? [];
        $location = $point['location'] ?? [];
        $line = is_array($address) ? ($address['line1'] ?? null) : null;
        $city = is_array($details) ? ($details['city'] ?? null) : null;
        $postcode = is_array($details) ? ($details['post_code'] ?? null) : null;
        $type = $point['type'] ?? [];
        if (($point['country'] ?? null) !== 'IT' || !is_string($line) || trim($line) === ''
            || !is_string($city) || trim($city) === '' || !is_string($postcode) || trim($postcode) === ''
            || !is_array($type) || (!in_array('parcel_locker', $type, true) && !in_array('pok', $type, true))) {
            throw new \RuntimeException('InPost returned an invalid operating point.');
        }
        $lat = is_array($location) ? ($location['latitude'] ?? null) : null;
        $lon = is_array($location) ? ($location['longitude'] ?? null) : null;
        if (($lat !== null && (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90))
            || ($lon !== null && (!is_numeric($lon) || (float) $lon < -180 || (float) $lon > 180))) {
            throw new \RuntimeException('InPost returned invalid coordinates.');
        }
        return [
            'point_id' => $point['name'], 'name' => (string) ($point['display_name'] ?? $point['name']),
            'type' => in_array('parcel_locker', $type, true) ? 'locker' : 'point',
            'address' => $line, 'city' => $city, 'province' => $details['province'] ?? null,
            'postal_code' => $postcode, 'latitude' => $lat, 'longitude' => $lon,
            'opening_hours' => isset($point['operating_hours_extended']) ? json_encode($point['operating_hours_extended'], JSON_THROW_ON_ERROR) : null,
        ];
    }
}
