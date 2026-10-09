<?php

declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Collection;

/** Recipient lookup is separate from financial aggregation to keep PII out of the ledger. */
final class WeeklySellerSummaryRecipientRepository
{
    private const MANUFACTURERS = 'manufacturer';

    public function __construct(private readonly Manager $db)
    {
    }

    /** @return Collection<int, object> */
    public function recipients(?int $artisanId = null): Collection
    {
        $query = $this->db->table(self::MANUFACTURERS)
            ->select(['id_manufacturer as artisan_id', 'email', 'name'])
            ->whereNotNull('email')
            ->where('email', '<>', '');

        if ($artisanId !== null) {
            $query->where('id_manufacturer', $artisanId);
        }

        return $query->orderBy('id_manufacturer')->get();
    }
}
