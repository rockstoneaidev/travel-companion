<?php

declare(strict_types=1);

namespace App\Domain\Trips\Contracts;

use App\Domain\Trips\Data\TripData;

/**
 * "Is the companion switched on for this trip, and whose is it?" — Trips' published
 * answer (conventions/01).
 *
 * Context needs it to decide whether a background ping may be stored at all (E29), and it
 * may not reach into `trips` to find out. The contract exists so the ingestion side can
 * ask without knowing that a trip is a row.
 */
interface TripLookup
{
    public function find(string $tripId): ?TripData;
}
