<?php

declare(strict_types=1);

use App\Models\User;

/*
|--------------------------------------------------------------------------
| The legal pages (Arts. 13–14; docs/legal/PRIVACY-NOTICE.md)
|--------------------------------------------------------------------------
|
| The load-bearing property is that a STRANGER can read them. Art. 13 wants the
| notice available "at the time when personal data are obtained" — which is the
| sign-up form — so a notice you can only reach once you have already signed up
| arrives after the decision it exists to inform.
|
| That is why these routes sit outside the auth group, and why the shell follows
| the reader rather than the route: signed in, they get the app's navigation like
| every other screen; signed out, they get the document. Wrapping them in the app
| shell unconditionally would put a sidebar full of authed links, and a user
| footer with no user, in front of someone who has no account.
|
*/

it('lets a stranger read the privacy notice and the terms', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/privacy-policy', '/terms-of-service']);

it('shows a signed-out reader the document, with no app shell to render', function () {
    $this->get('/privacy-policy')->assertInertia(
        fn ($page) => $page->where('auth.user', null)->where('auth.permissions', []),
    );
});

it('gives a signed-in reader the same navigation as every other screen', function () {
    $this->actingAs(User::factory()->create())
        ->get('/privacy-policy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.id', User::first()->id));
});

it('never hard-codes the retention period — it comes from config', function () {
    config()->set('privacy.raw_location_retention_days', 44);

    $this->get('/privacy-policy')->assertInertia(
        fn ($page) => $page->where('retentionDays', 44),
    );
});
