<?php

declare(strict_types=1);

use App\Auth\Models\SocialAccount;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Settings: passwords for Google users, and disconnecting (E22)
|--------------------------------------------------------------------------
*/

/** An account created through Google: verified, no password. */
function passwordlessUser(): User
{
    $user = User::factory()->create(['password' => null]);

    SocialAccount::factory()->for($user)->create(['provider' => 'google']);

    return $user;
}

it('lets a google user set a first password without confirming a current one', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasPassword())->toBeTrue();

    // And it is the password they typed — they can now log in without Google.
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'new-password-1234']);
    $this->assertAuthenticatedAs($user->fresh());
});

it('rejects a current_password sent by a user who has none', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'anything-at-all',
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
        ])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->hasPassword())->toBeFalse();
})->note('`missing` rather than ignored: a client sending it has misunderstood the state.');

it('still demands the current password from a user who has one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
        ])
        ->assertSessionHasErrors('current_password');
});

it('refuses to disconnect the only way into the account', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->delete(route('social.destroy', ['provider' => 'google']))
        ->assertSessionHasErrors('provider');

    expect($user->socialAccounts()->count())->toBe(1);
})->note('No password and no other provider — removing this locks the user out for good.');

it('disconnects google once a password exists', function () {
    $user = passwordlessUser();

    $user->forceFill(['password' => bcrypt('a-real-password')])->save();

    $this->actingAs($user)
        ->from(route('password.edit'))
        ->delete(route('social.destroy', ['provider' => 'google']))
        ->assertSessionHas('status', 'google-disconnected');

    expect($user->socialAccounts()->count())->toBe(0);
});

it('404s on a provider we do not support', function () {
    $this->actingAs(User::factory()->create())
        ->delete(route('social.destroy', ['provider' => 'facebook']))
        ->assertNotFound();
});

it('shows the sign-in settings screen with the password state and linked accounts', function () {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->get(route('password.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/password')
            ->where('hasPassword', false)
            ->has('socialAccounts', 1)
            ->where('socialAccounts.0.provider', 'google')
        );
});
