<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserRoleController;
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
});
