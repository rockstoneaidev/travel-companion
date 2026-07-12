<?php

declare(strict_types=1);

namespace App\Auth\Actions;

use App\Auth\Data\SocialIdentity;
use App\Auth\Exceptions\ProviderEmailNotVerified;
use App\Auth\Exceptions\SocialAccountAlreadyLinked;
use App\Auth\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Attach a provider identity to a known user, or refresh the one already there.
 *
 * Two callers: the settings "connect Google" flow (user already logged in), and
 * ResolveSocialIdentity (user just established by the callback).
 */
final readonly class LinkSocialAccount
{
    public function handle(User $user, SocialIdentity $identity): SocialAccount
    {
        if (! $identity->emailVerified) {
            throw new ProviderEmailNotVerified($identity->provider, $identity->normalizedEmail());
        }

        return DB::transaction(function () use ($user, $identity): SocialAccount {
            $existing = SocialAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->providerUserId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->user_id !== $user->id) {
                throw SocialAccountAlreadyLinked::toAnotherUser($identity->provider);
            }

            if ($existing === null && $this->hasOtherIdentityFromProvider($user, $identity)) {
                throw SocialAccountAlreadyLinked::forThisUser($identity->provider);
            }

            $account = $existing ?? new SocialAccount([
                'user_id' => $user->id,
                'provider' => $identity->provider->value,
                'provider_user_id' => $identity->providerUserId,
            ]);

            // The provider is authoritative for these and they drift (renamed
            // account, new avatar) — refresh on every login rather than keeping
            // whatever was true at first sign-in.
            $account->fill([
                'email' => $identity->normalizedEmail(),
                'name' => $identity->name,
                'avatar_url' => $identity->avatarUrl,
                'last_login_at' => now(),
            ])->save();

            return $account;
        });
    }

    /** The (user_id, provider) unique index, as a question instead of a 23505. */
    private function hasOtherIdentityFromProvider(User $user, SocialIdentity $identity): bool
    {
        return SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $identity->provider->value)
            ->exists();
    }
}
