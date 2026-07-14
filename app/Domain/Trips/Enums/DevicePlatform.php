<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/** Where a push token can be delivered to (E29; PRD §8.2, §13.1). */
enum DevicePlatform: string
{
    use HasOptions;

    case Ios = 'ios';
    case Android = 'android';

    /** The installable PWA — Web Push, so Phase 1's client is not a second-class citizen. */
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iOS',
            self::Android => 'Android',
            self::Web => 'Web',
        };
    }
}
