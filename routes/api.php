<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (versioned)
|--------------------------------------------------------------------------
|
| These JSON endpoints are the contract the mobile client (Phase 2) will
| consume. Per CLAUDE.md, all product logic lives in app/Domain services;
| both these API controllers and the Inertia web controllers are thin
| delivery wrappers over the same services. Keep the API versioned from
| day one so the native client is additive, never a backend rewrite.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user())
        ->middleware('auth:sanctum')
        ->name('user');

    // Opportunity / trip / feedback endpoints land here (PRD §14.5).
});
