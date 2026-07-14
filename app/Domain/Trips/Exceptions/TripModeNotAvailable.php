<?php

declare(strict_types=1);

namespace App\Domain\Trips\Exceptions;

use App\Domain\Trips\Enums\TripStatus;
use RuntimeException;

final class TripModeNotAvailable extends RuntimeException
{
    public static function tripNotLive(string $tripId, TripStatus $status): self
    {
        return new self("Trip {$tripId} is {$status->value} — Trip Mode follows a journey in progress, and there is none.");
    }
}
