<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects guests to login', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('forbids users without a role', function (string $path) {
    $this->actingAs(profilingAsked(User::factory()->create()))->get($path)->assertForbidden();
})->with(['/admin', '/admin/users', '/admin/activity']);

it('lets an admin into the console', function (string $path) {
    $this->actingAs(profilingAsked(User::factory()->admin()->create()))->get($path)->assertOk();
})->with(['/admin', '/admin/users', '/admin/activity']);

it('lets a superadmin into the console via Gate::before', function (string $path) {
    $this->actingAs(profilingAsked(User::factory()->superadmin()->create()))->get($path)->assertOk();
})->with(['/admin', '/admin/users', '/admin/activity']);

it('forbids an admin from updating roles', function () {
    $target = profilingAsked(User::factory()->create());

    $this->actingAs(profilingAsked(User::factory()->admin()->create()))
        ->put("/admin/users/{$target->id}/roles", ['roles' => ['admin']])
        ->assertForbidden();
});

it('gates horizon and pulse on ops_view', function () {
    $user = profilingAsked(User::factory()->create());
    $admin = profilingAsked(User::factory()->admin()->create());

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewPulse'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue();
});

it('shares the passing permissions with the frontend', function () {
    $this->actingAs(profilingAsked(User::factory()->admin()->create()))
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.permissions', ['admin_access', 'ops_view', 'users_view', 'activity_view']));
});

it('shares no permissions for a regular user', function () {
    $this->actingAs(profilingAsked(User::factory()->create()))
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permissions', []));
});
