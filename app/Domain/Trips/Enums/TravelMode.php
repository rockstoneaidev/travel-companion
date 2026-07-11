<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Declared per Explore Session (PRD §6.6). Drives reach, coverage shape, and
 * travel-time estimator constants (PRD §9.2/§10, conventions/12).
 */
enum TravelMode: string
{
    use HasOptions;

    case Walk = 'walk';
    case Bike = 'bike';
    case Drive = 'drive';

    /** Effective speed for the Stage A estimator (PRD §10) in km/h. */
    public function effectiveSpeedKmh(): float
    {
        return match ($this) {
            self::Walk => 4.5,
            self::Bike => 14.0,
            self::Drive => 40.0,
        };
    }

    /** Path factor: straight-line → real-network multiplier (PRD §10). */
    public function pathFactor(): float
    {
        return match ($this) {
            self::Walk, self::Bike => 1.30,
            self::Drive => 1.35,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
