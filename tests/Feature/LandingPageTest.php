<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows the landing page to a guest, with both doors in', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('welcome'));
});

it('sends a logged-in traveller straight to the app, not the marketing page', function () {
    $this->actingAs(User::factory()->create());

    // A logged-in user has no use for the front page — the whole point of the redirect.
    $this->get('/')->assertRedirect(route('dashboard'));
});
