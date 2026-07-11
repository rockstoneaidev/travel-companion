<?php

declare(strict_types=1);

use App\Admin\Actions\SyncUserRoles;
use App\Admin\Exceptions\OperatorCannotModifyOwnRoles;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('syncs roles and writes an audit entry', function () {
    $actor = User::factory()->superadmin()->create();
    $target = User::factory()->create();

    app(SyncUserRoles::class)($actor, $target, [Role::Admin]);

    expect($target->refresh()->hasRole(Role::Admin->value))->toBeTrue();

    $entry = Activity::query()->where('description', 'user.roles_synced')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($actor->id)
        ->and($entry->subject_id)->toBe($target->id)
        ->and($entry->properties['old'])->toBe([])
        ->and($entry->properties['new'])->toBe([Role::Admin->value]);
});

it('refuses to modify the actor\'s own roles', function () {
    $actor = User::factory()->superadmin()->create();

    expect(fn () => app(SyncUserRoles::class)($actor, $actor, []))
        ->toThrow(OperatorCannotModifyOwnRoles::class)
        ->and($actor->refresh()->hasRole(Role::Superadmin->value))->toBeTrue();
});

it('lets a superadmin update roles over HTTP', function () {
    $actor = User::factory()->superadmin()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->from('/admin/users')
        ->put("/admin/users/{$target->id}/roles", ['roles' => [Role::Admin->value]])
        ->assertRedirect('/admin/users');

    expect($target->refresh()->hasRole(Role::Admin->value))->toBeTrue();
});

it('maps self-modification to a form error over HTTP', function () {
    $actor = User::factory()->superadmin()->create();

    $this->actingAs($actor)
        ->from('/admin/users')
        ->put("/admin/users/{$actor->id}/roles", ['roles' => []])
        ->assertRedirect('/admin/users')
        ->assertSessionHasErrors('roles');

    expect($actor->refresh()->hasRole(Role::Superadmin->value))->toBeTrue();
});

it('rejects unknown role values', function () {
    $actor = User::factory()->superadmin()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put("/admin/users/{$target->id}/roles", ['roles' => ['owner']])
        ->assertSessionHasErrors('roles.0');
});

it('grants a role from the CLI', function () {
    $user = User::factory()->create();

    $this->artisan('user:assign-role', ['email' => $user->email, 'role' => 'superadmin'])
        ->assertSuccessful();

    expect($user->refresh()->hasRole(Role::Superadmin->value))->toBeTrue()
        ->and(Activity::query()->where('description', 'user.role_assigned')->exists())->toBeTrue();
});

it('fails cleanly on an unknown role or user', function () {
    $this->artisan('user:assign-role', ['email' => 'nobody@example.com', 'role' => 'superadmin'])
        ->assertFailed();

    $this->artisan('user:assign-role', ['email' => User::factory()->create()->email, 'role' => 'owner'])
        ->assertFailed();
});
