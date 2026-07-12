<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Actions\LinkSocialAccount;
use App\Auth\Actions\ResolveSocialIdentity;
use App\Auth\Data\SocialIdentity;
use App\Auth\Enums\SocialProvider;
use App\Auth\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * The web (redirect) adapter for Google sign-in.
 *
 * All it does is turn Socialite's response into a SocialIdentity and hand it to
 * the domain. The Phase 2 mobile client will do the same from an ID token; the
 * decision — link, register, refuse — lives in App\Auth and is shared.
 *
 * One callback serves two intents: a guest is signing in, a logged-in user is
 * connecting Google to the account they already have. The second is the escape
 * hatch from AccountLinkingBlocked, so it has to exist.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly ResolveSocialIdentity $resolve,
        private readonly LinkSocialAccount $link,
    ) {}

    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver(SocialProvider::Google->driver())->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $connecting = $request->user() !== null;

        // The user hit "Cancel" on Google's consent screen, or Google refused.
        // Not an error worth a scary message — just put them back.
        if ($request->has('error')) {
            return $this->back($connecting);
        }

        try {
            $identity = $this->identityFrom(
                Socialite::driver(SocialProvider::Google->driver())->user()
            );
        } catch (Throwable $e) {
            // Expired state, tampered callback, Google 5xx. Nothing the user can
            // act on beyond trying again.
            Log::warning('Google OAuth callback failed.', ['exception' => $e]);

            return $this->back($connecting)
                ->withErrors(['email' => 'We could not complete the sign-in with Google. Please try again.']);
        }

        try {
            if ($connecting) {
                $this->link->handle($request->user(), $identity);

                return to_route('password.edit')->with('status', 'google-connected');
            }

            Auth::login($this->resolve->handle($identity), remember: true);
            $request->session()->regenerate();

            return to_route('dashboard');
        } catch (SocialAuthException $e) {
            return $this->back($connecting)->withErrors(['email' => $e->userMessage()]);
        }
    }

    private function identityFrom(SocialiteUser $user): SocialIdentity
    {
        return new SocialIdentity(
            provider: SocialProvider::Google,
            providerUserId: (string) $user->getId(),
            email: (string) $user->getEmail(),
            // Google's OpenID claim. Absent means absent — never assume true.
            emailVerified: (bool) ($user->user['email_verified'] ?? false),
            name: $user->getName(),
            avatarUrl: $user->getAvatar(),
        );
    }

    private function back(bool $connecting): RedirectResponse
    {
        return to_route($connecting ? 'password.edit' : 'login');
    }
}
