<?php

declare(strict_types=1);

namespace App\Domain\Context\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How hard the phone was working when it noticed this (PRD §13.4, E29).
 *
 * The tiers are a battery contract, and the product lives or dies on it — PRD risk 4:
 * "background location kills trust/battery or fails app review". A companion that drains
 * a phone is uninstalled by lunchtime, whatever it recommended.
 *
 *     High     app open, navigating, or Trip Mode explicitly on
 *     Medium   walking / exploring
 *     Low      significant-change location, geofences, coarse region changes
 *     (none)   at home, outside a trip, or paused — and then there is NO event at all,
 *              which is why "no tracking" is not a case here. A tier you can record is a
 *              tier you were tracking in.
 */
enum PowerTier: string
{
    use HasOptions;

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High accuracy',
            self::Medium => 'Medium',
            self::Low => 'Low power',
        };
    }
}
