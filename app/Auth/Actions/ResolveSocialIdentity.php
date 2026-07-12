<?php

declare(strict_types=1);

namespace App\Auth\Actions;

use App\Auth\Data\SocialIdentity;
use App\Auth\Exceptions\AccountLinkingBlocked;
use App\Auth\Exceptions\ProviderEmailNotVerified;
use App\Auth\Exceptions\RegistrationNotAllowed;
use App\Auth\Models\SocialAccount;
use App\Auth\Services\RegistrationAllowlist;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * "Continue with Google", as a decision: find the user this identity belongs to,
 * link it to the user who already owns the email, or register a new one.
 *
 * Transport-free on purpose (CLAUDE.md, API-first): the web callback and the
 * Phase 2 mobile ID-token flow both hand it a SocialIdentity and get a User.
 *
 * The order of the three branches is the security design; see mayLinkByEmail().
 */
final readonly class ResolveSocialIdentity
{
    public function __construct(
        private RegistrationAllowlist $allowlist,
        private LinkSocialAccount $link,
    ) {}

    public function handle(SocialIdentity $identity): User
    {
        if (! $identity->emailVerified) {
            throw new ProviderEmailNotVerified($identity->provider, $identity->normalizedEmail());
        }

        return DB::transaction(function () use ($identity): User {
            // 1. Known identity: the provider id, not the email, is the login key.
            //    A Google user who changes their gmail address is still this user.
            $account = SocialAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->providerUserId)
                ->first();

            if ($account !== null) {
                $user = $account->user;
                $this->link->handle($user, $identity);   // refresh name/avatar/last_login_at

                return $user;
            }

            // 2. Known email, first time through this provider.
            $user = User::query()
                ->where('email', $identity->normalizedEmail())
                ->first();

            if ($user !== null) {
                if (! $this->mayLinkByEmail($user)) {
                    throw new AccountLinkingBlocked($identity->normalizedEmail());
                }

                $this->link->handle($user, $identity);

                return $user;
            }

            // 3. Nobody here by that name — this is a registration, and the
            //    allowlist governs every registration path, not just the form.
            if (! $this->allowlist->allows($identity->normalizedEmail())) {
                throw new RegistrationNotAllowed($identity->normalizedEmail());
            }

            // forceFill, not create(): `email_verified_at` is deliberately not
            // mass-assignable on User — nothing that reads a request array should
            // ever be able to mark an email verified.
            $user = new User;

            $user->forceFill([
                'name' => $identity->displayName(),
                'email' => $identity->normalizedEmail(),
                // No password, and no random placeholder either: null *is* the
                // state, and it is what settings reads to offer "set a password".
                'password' => null,
                // Google proved the inbox; making the user prove it again would
                // be theatre.
                'email_verified_at' => now(),
            ])->save();

            $this->link->handle($user, $identity);

            event(new Registered($user));

            return $user;
        });
    }

    /**
     * May we merge this provider identity into the existing local account that
     * holds the same email?
     *
     * Safe only if *someone proved that inbox belongs to that account's owner*.
     * Two things can prove it:
     *
     *  - the account's email is verified — the direct proof; or
     *  - registration is closed (pre-launch allowlist), so the account could
     *    only have been created by an invited address in the first place. There
     *    is no attacker who could have pre-registered the victim's email.
     *
     * With email verification deferred (E22), the allowlist is the *only* thing
     * holding this rule up. That is deliberate and it is why the condition is
     * written this way rather than hard-coded to `true`: the day someone empties
     * ALLOWED_REGISTRATION_EMAILS, unverified accounts stop auto-linking and
     * users get told to log in with their password instead — annoying, and
     * loudly so. The alternative is a silent account-takeover vector.
     *
     * Ship email verification and this resolves itself: verified accounts link,
     * whatever the allowlist says.
     */
    private function mayLinkByEmail(User $user): bool
    {
        return $user->email_verified_at !== null
            || $this->allowlist->isClosed();
    }
}
