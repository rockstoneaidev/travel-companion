<?php

declare(strict_types=1);

namespace App\Auth\Actions;

use App\Auth\Enums\SocialProvider;
use App\Auth\Exceptions\CannotDisconnectLastAuthMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Unlink a provider from an account, unless it is the only way in.
 */
final readonly class DisconnectSocialAccount
{
    public function handle(User $user, SocialProvider $provider): void
    {
        DB::transaction(function () use ($user, $provider): void {
            $account = $user->socialAccounts()
                ->where('provider', $provider->value)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                return;   // already gone; a double-submit is not an error
            }

            if (! $this->hasAnotherWayIn($user, $provider)) {
                throw new CannotDisconnectLastAuthMethod;
            }

            $account->delete();
        });
    }

    private function hasAnotherWayIn(User $user, SocialProvider $provider): bool
    {
        if ($user->hasPassword()) {
            return true;
        }

        return $user->socialAccounts()
            ->where('provider', '!=', $provider->value)
            ->exists();
    }
}
