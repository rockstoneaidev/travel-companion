<?php

declare(strict_types=1);

use App\Auth\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
|--------------------------------------------------------------------------
| Sign in with Google (E22)
|--------------------------------------------------------------------------
|
| The linking matrix is the whole feature. Getting one cell wrong is either a
| lockout or an account takeover, so every cell has a test.
|
*/

/** Stand in for what Google hands Socialite after a successful consent. */
function googleUser(array $overrides = []): SocialiteUser
{
    $user = new SocialiteUser;

    $user->map([
        'id' => $overrides['id'] ?? '102938475610293847561',
        'name' => $overrides['name'] ?? 'Mats Bergsten',
        'email' => $overrides['email'] ?? 'mats@beet.se',
        'avatar' => $overrides['avatar'] ?? 'https://lh3.googleusercontent.com/a/default',
    ]);

    // The raw OpenID claims. `email_verified` is the load-bearing one.
    $user->user = ['email_verified' => $overrides['email_verified'] ?? true];

    return $user;
}

function fakeGoogleReturns(SocialiteUser $user): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

/** Pre-launch: invite-only. The state the app actually ships in. */
function closedRegistration(array $emails = ['mats@beet.se']): void
{
    config(['auth.allowed_registration_emails' => $emails]);
}

function openRegistration(): void
{
    config(['auth.allowed_registration_emails' => []]);
}

it('sends the user to google', function () {
    config(['services.google' => [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'redirect' => 'http://localhost/auth/google/callback',
    ]]);

    $this->get(route('auth.google.redirect'))
        ->assertRedirectContains('accounts.google.com');
});

it('registers an allowlisted google user with no password and a verified email', function () {
    closedRegistration();
    fakeGoogleReturns(googleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'mats@beet.se')->sole();

    expect($user->password)->toBeNull()                    // null is the state, not a placeholder
        ->and($user->email_verified_at)->not->toBeNull()   // Google proved the inbox
        ->and($user->name)->toBe('Mats Bergsten');

    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => '102938475610293847561',
    ]);
});

it('refuses a google account that is not on the registration allowlist', function () {
    closedRegistration(['mats@beet.se']);
    fakeGoogleReturns(googleUser(['email' => 'stranger@example.com', 'id' => '999']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
})->note('The callback is a second registration path — the allowlist governs it too.');

it('refuses an identity whose email google has not verified', function () {
    closedRegistration();
    fakeGoogleReturns(googleUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'mats@beet.se']);
});

it('treats a missing email_verified claim as unverified', function () {
    closedRegistration();

    $user = googleUser();
    $user->user = [];   // claim absent entirely

    fakeGoogleReturns($user);

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('logs a returning google user in without creating a second account', function () {
    closedRegistration();

    $user = User::factory()->create(['email' => 'mats@beet.se']);
    SocialAccount::factory()->for($user)->create([
        'provider' => 'google',
        'provider_user_id' => '102938475610293847561',
        'last_login_at' => now()->subMonth(),
    ]);

    fakeGoogleReturns(googleUser(['name' => 'Mats B.']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(1)
        // The provider is authoritative for the profile, and it drifts.
        ->and($user->socialAccounts()->sole()->name)->toBe('Mats B.')
        ->and($user->socialAccounts()->sole()->last_login_at->isToday())->toBeTrue();
});

it('identifies a returning user by provider id, not email', function () {
    closedRegistration(['mats@beet.se']);

    $user = User::factory()->create(['email' => 'mats@beet.se']);
    SocialAccount::factory()->for($user)->create([
        'provider' => 'google',
        'provider_user_id' => '102938475610293847561',
    ]);

    // Same Google account, but they changed the address on it.
    fakeGoogleReturns(googleUser(['email' => 'mats.new@gmail.com']));

    $this->get(route('auth.google.callback'));

    // Logged in as the same user — and NOT rejected as a non-allowlisted
    // registration, because this was never a registration.
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('links google to the existing account with the same email while registration is closed', function () {
    closedRegistration(['mats@beet.se']);

    // Verification is deferred, so this real, legitimate user is unverified.
    $user = User::factory()->unverified()->create(['email' => 'mats@beet.se']);
    $passwordBefore = $user->password;

    fakeGoogleReturns(googleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->password)->toBe($passwordBefore)   // still has their password
        ->and($user->socialAccounts()->count())->toBe(1);
})->note('The allowlist is what makes this safe: only invited addresses could have created the account.');

it('refuses to link google to an unverified account once registration is open', function () {
    openRegistration();

    // Nobody proved this inbox — with open registration, an attacker could have
    // created it in the victim's name. Merging here hands them the account.
    $user = User::factory()->unverified()->create(['email' => 'victim@example.com']);

    fakeGoogleReturns(googleUser(['email' => 'victim@example.com']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->socialAccounts()->count())->toBe(0);
})->note('Pre-registration account takeover. The escape hatch is: log in with your password, then connect Google from settings.');

it('links google to a verified account even once registration is open', function () {
    openRegistration();

    $user = User::factory()->create(['email' => 'known@example.com']);   // verified
    fakeGoogleReturns(googleUser(['email' => 'known@example.com']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->count())->toBe(1);
})->note('Verification is the real fix; shipping it makes the allowlist condition redundant.');

it('connects google to the account a signed-in user already has', function () {
    openRegistration();

    $user = User::factory()->create(['email' => 'someone@example.com']);

    // A different address on the Google account than the one they registered
    // with — connecting while signed in does not need them to match.
    fakeGoogleReturns(googleUser(['email' => 'someone.else@gmail.com']));

    $this->actingAs($user)
        ->get(route('auth.google.callback'))
        ->assertRedirect(route('password.edit', absolute: false))
        ->assertSessionHas('status', 'google-connected');

    expect($user->socialAccounts()->sole()->email)->toBe('someone.else@gmail.com');
});

it('refuses to connect a google account already linked to someone else', function () {
    openRegistration();

    $owner = User::factory()->create();
    SocialAccount::factory()->for($owner)->create([
        'provider' => 'google',
        'provider_user_id' => '102938475610293847561',
    ]);

    $other = User::factory()->create();
    fakeGoogleReturns(googleUser());

    $this->actingAs($other)
        ->get(route('auth.google.callback'))
        ->assertSessionHasErrors('email');

    expect($other->socialAccounts()->count())->toBe(0)
        ->and(SocialAccount::count())->toBe(1);
});

it('bounces the user back when they cancel at google', function () {
    $this->get(route('auth.google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasNoErrors();

    $this->assertGuest();
});
