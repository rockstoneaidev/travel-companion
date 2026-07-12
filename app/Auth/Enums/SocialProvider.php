<?php

declare(strict_types=1);

namespace App\Auth\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * OAuth identity providers we accept a login from (E22).
 *
 * Google only in Phase 1. Apple is expected in Phase 2 — the App Store requires
 * it once any third-party login exists — which is why this is an enum and a
 * `social_accounts.provider` column rather than a hard-coded "google".
 */
enum SocialProvider: string
{
    use HasOptions;

    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
        };
    }

    /** The Socialite driver name. Same string today; not guaranteed to stay so. */
    public function driver(): string
    {
        return $this->value;
    }
}
