<?php

declare(strict_types=1);

namespace App\Domain\Trips\Contracts;

/**
 * PRD §16 / §14.5 — `DELETE /api/v1/trips/{trip}/location-history`. Each module
 * erases its own tables; Privacy orchestrates. This is the Trips half: the trip
 * anchor and every session origin/destination.
 */
interface TripLocationEraser
{
    /** @return int number of rows whose raw coordinates were erased */
    public function eraseForTrip(string $tripId): int;
}
