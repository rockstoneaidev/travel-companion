<?php

declare(strict_types=1);

namespace App\Domain\Curation\Enums;

use App\Enums\Concerns\HasOptions;

/** Regional Knowledge Pack sections with their TTL classes (CURATION §2, DATA-SOURCES §8). */
enum PackSection: string
{
    use HasOptions;

    case Identity = 'identity';
    case Seasonal = 'seasonal';
    case FoodDrink = 'food_drink';
    case Heritage = 'heritage';
    case Nature = 'nature';
    case Now = 'now';
    case Craft = 'craft';
    case Stories = 'stories';

    /** "now" hourly–daily · seasonal quarterly · identity/heritage yearly. */
    public function ttlClass(): string
    {
        return match ($this) {
            self::Now => 'daily',
            self::Seasonal => 'quarterly',
            default => 'yearly',
        };
    }
}
