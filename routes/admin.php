<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\CostController;
use App\Http\Controllers\Admin\CurationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmulatorController;
use App\Http\Controllers\Admin\EntityResolutionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Admin\WorldModelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console (docs/ADMIN.md)
|--------------------------------------------------------------------------
|
| The third delivery surface beside Web/ and Api/V1 — thin Inertia
| controllers over app/Admin (platform) and app/Domain (product) code.
| Permissions are spatie-backed gates; superadmin passes all of them via
| Gate::before. Role updates are authorized in UpdateUserRolesRequest.
|
*/

Route::middleware(['auth', 'can:admin_access'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('users', [UserController::class, 'index'])
        ->middleware('can:users_view')
        ->name('users.index');

    Route::put('users/{user}/roles', [UserRoleController::class, 'update'])
        ->name('users.roles.update');

    Route::get('activity', [ActivityController::class, 'index'])
        ->middleware('can:activity_view')
        ->name('activity.index');

    /*
    | Cost (docs/COST.md §7.3–§7.4). The explorer is read-only and gated by
    | `costs_view`; the kill-switch is a lever that makes the product quieter for
    | everyone, so it is superadmin-only (which `privacy_operate` already means in
    | ADMIN §3.2 — superadmin passes every gate via Gate::before, and no role holds
    | this permission directly).
    */
    Route::middleware('can:costs_view')->group(function () {
        Route::get('costs', [CostController::class, 'index'])->name('costs.index');
        Route::get('costs/export', [CostController::class, 'export'])->name('costs.export');
    });

    Route::post('costs/pause', [CostController::class, 'pause'])
        ->middleware('can:cost_pause')
        ->name('costs.pause');

    // Curation review (CURATION §3 step 4) — gated by admin_access for now;
    // a dedicated curation_review permission arrives with the roles pass.
    Route::get('curation', [CurationController::class, 'index'])->name('curation.index');
    Route::put('curation/{item}/approve', [CurationController::class, 'approve'])->name('curation.approve');
    Route::put('curation/{item}/reject', [CurationController::class, 'reject'])->name('curation.reject');
    Route::post('curation/{item}/ground', [CurationController::class, 'ground'])->name('curation.ground');

    // World-model ops: the ingest/resolve buttons (runs on Horizon).
    Route::get('world-model', [WorldModelController::class, 'index'])->name('world-model.index');
    Route::post('world-model/{region}/build', [WorldModelController::class, 'build'])->name('world-model.build');

    /*
    | Draft the region's curation pack (CURATION §4). Deliberate rather than a phase
    | of the build — it calls the LLM once per candidate and costs real money — but
    | VISIBLE, which it was not: it was an artisan command you had to know existed,
    | so the review queue sat empty for days and looked broken.
    */
    Route::post('world-model/{region}/draft-pack', [WorldModelController::class, 'draft'])->name('world-model.draft');

    // Entity-resolution review queue (ENTITY-RESOLUTION §3 stage 4): the pairs
    // the resolver refused to guess about. Until a human looks, the world model
    // is holding a probable duplicate.
    Route::get('entity-resolution', [EntityResolutionController::class, 'index'])->name('entity-resolution.index');
    Route::put('entity-resolution/{decision}/merge', [EntityResolutionController::class, 'merge'])->name('entity-resolution.merge');
    Route::put('entity-resolution/{decision}/distinct', [EntityResolutionController::class, 'keepDistinct'])->name('entity-resolution.distinct');

    /*
    | Position emulation (ADMIN §6) — the glass cockpit.
    |
    | `location_emulate` is superadmin-only and held by NO role (ADMIN §3.2), because
    | this drives the real pipeline from a fabricated position: real scouts, real
    | scoring, real money. What keeps it from also producing real *metrics* is the
    | `context_source` flag on the session, and the flag is only as trustworthy as the
    | list of people who can raise it.
    */
    Route::middleware('can:'.Permission::EmulateLocation->value)->group(function () {
        Route::get('emulator', [EmulatorController::class, 'index'])->name('emulator.index');
        Route::post('emulator/sessions', [EmulatorController::class, 'store'])->name('emulator.store');

        // The tick. A real context event, through the real ingestion boundary — there is
        // no emulator-shaped shortcut into the pipeline, and that is the whole design.
        Route::post('emulator/positions', [EmulatorController::class, 'move'])->name('emulator.move');

        // "What WOULD this serve?" — the pure planning pass. Writes nothing.
        Route::post('emulator/dry-run', [EmulatorController::class, 'dryRun'])->name('emulator.dry-run');

        Route::delete('emulator/sessions', [EmulatorController::class, 'destroy'])->name('emulator.destroy');
    });
});
