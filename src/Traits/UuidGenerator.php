<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use Hashids\Hashids;

trait UuidGenerator
{
    private function getHasher(string $saltSuffix = '', int $minLenght = 8): Hashids
    {
        // Usa l'APP_KEY come base + un suffisso per differenziare i tipi di ID
        $salt = env('APP_KEY') . $saltSuffix;
        return new Hashids($salt, $minLenght); // 12 è la lunghezza minima della stringa
    }

    public function encodeId(int $id, string $type = 'generic'): string
    {
        return $this->getHasher($type)->encode($id);
    }

    public function decodeId(string $hash, string $type = 'generic'): int
    {
        $decoded = $this->getHasher($type)->decode($hash);

        if (empty($decoded)) {
            throw new \RuntimeException("Hash non valido o manipolato.");
        }

        return (int) $decoded[0];
    }
}