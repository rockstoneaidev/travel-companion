<?php

declare(strict_types=1);

use App\Cost\Services\SpendGuard;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
});

function spendRow(int $userId, int $micros = 5_000, string $resource = 'routes_essentials'): void
{
    DB::table('cost_events')->insert([
        'occurred_at' => now(),
        'actor_kind' => 'user',
        'category' => 'api',
        'vendor' => 'google_maps',
        'resource' => $resource,
        'user_id' => $userId,
        'calls' => 1,
        'billed_usd_micros' => $micros,
        'would_have_billed_usd_micros' => $micros,
        'price_version' => '2026-07',
        'created_at' => now(),
    ]);
}

it('shows an operator what the product is spending', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);

    spendRow($admin->id);

    $this->actingAs($admin)
        ->get('/admin/costs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/costs')
            ->where('data.totals.billedMicros', 5_000)
            ->where('controls.priceVersion', '2026-07'));
});

it('never serialises spend into the props of an operator without costs_view', function () {
    // Not "the component hides it" — the numbers never leave the server (ADMIN §3).
    $user = User::factory()->create();
    $user->givePermissionTo('admin_access');

    spendRow($user->id);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('cost', null));

    $this->actingAs($user)->get('/admin/costs')->assertForbidden();
});

it('puts the cost strip on the dashboard for an operator who may see it', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);

    spendRow($admin->id, 5_000);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cost.todayMicros', 5_000)
            ->where('cost.topLineItem.resource', 'routes_essentials'));
});

it('lets only a superadmin pause every paid call', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);   // has costs_view; must NOT have cost_pause

    // Seeing what we spend is an operator's job. Making the product quieter for every
    // user in the field is the bill-owner's (ADMIN §3.2 — no role holds `cost_pause`).
    $this->actingAs($admin)->post('/admin/costs/pause')->assertForbidden();

    expect(app(SpendGuard::class)->paused())->toBeFalse();

    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::Superadmin->value);

    $this->actingAs($superadmin)->post('/admin/costs/pause')->assertRedirect();

    expect(app(SpendGuard::class)->paused())->toBeTrue();

    // ...and it is in the audit log, because it is a lever that changes what every user sees.
    expect(DB::table('activity_log')->where('description', 'paused all paid calls')->count())->toBe(1);

    $this->actingAs($superadmin)->post('/admin/costs/pause', ['resume' => true])->assertRedirect();

    expect(app(SpendGuard::class)->paused())->toBeFalse();
});

it('exports the ledger as a file, because accounting does not want a screenshot', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);

    spendRow($admin->id, 5_000);

    $response = $this->actingAs($admin)->get('/admin/costs/export?range=today');

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    // USD in the file, micros in the database: a spreadsheet is read by a human.
    expect($csv)->toContain('billed_usd')
        ->and($csv)->toContain('0.005000')
        ->and($csv)->toContain('routes_essentials')
        // And no column that could carry a coordinate (ROPA B1).
        ->and($csv)->not->toContain('http');
});

it('drills down: a filter narrows the same page rather than opening another report', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin->value);

    spendRow($admin->id, 5_000, 'routes_essentials');
    spendRow($admin->id, 17_000, 'place_details_pro');

    $this->actingAs($admin)
        ->get('/admin/costs?range=today&resource=routes_essentials')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('data.totals.billedMicros', 5_000)   // not 22,000
            ->where('data.filters.resource', 'routes_essentials'));
});
