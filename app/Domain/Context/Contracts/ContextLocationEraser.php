<?php

declare(strict_types=1);

namespace App\Domain\Context\Contracts;

/**
 * The Context half of trip-level location deletion (PRD §16). Privacy
 * orchestrates; each module erases its own tables.
 */
interface ContextLocationEraser
{
    /** @return int number of context events whose raw coordinates were erased */
    public function eraseForTrip(string $tripId): int;
}
