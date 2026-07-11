<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

/*
|--------------------------------------------------------------------------
| Persistent login — logins are always remembered
|--------------------------------------------------------------------------
|
| Product decision: no "remember me" checkbox; every login issues the
| long-lived remember cookie so the login wall appears once per device.
| Changing the password rotates the remember token, logging out every
| OTHER remembered device (stolen-device recovery) while keeping this one.
|
*/

function recallerFrom($response): ?Cookie
{
    $name = Auth::guard('web')->getRecallerName();

    return collect($response->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === $name);
}

it('always issues a long-lived remember cookie on login', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();

    $recaller = recallerFrom($response);

    expect($recaller)->not->toBeNull()
        ->and($recaller->getExpiresTime())->toBeGreaterThan(now()->addYear()->getTimestamp())
        ->and($user->refresh()->remember_token)->not->toBeNull();
});

it('issues the remember cookie on registration too', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'new-user@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    expect(recallerFrom($response))->not->toBeNull();
});

it('re-authenticates from the remember cookie after the session dies', function () {
    $user = User::factory()->create();

    $recaller = recallerFrom($this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]));

    // Fresh browser state: no session, only the remember cookie. The value
    // from the response is already encrypted, so send it unencrypted-by-the-
    // test-harness and let the EncryptCookies middleware decrypt it once.
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->get('/dashboard')
        ->assertOk();
});

it('rotates the remember token on password change but keeps this device', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $tokenBefore = $user->refresh()->remember_token;

    $response = $this->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'new-Secret-password-1',
        'password_confirmation' => 'new-Secret-password-1',
    ]);

    $response->assertSessionHasNoErrors();

    // Other devices' recallers are dead, this device got a fresh one.
    expect($user->refresh()->remember_token)->not->toBe($tokenBefore)
        ->and(recallerFrom($response))->not->toBeNull();
});
