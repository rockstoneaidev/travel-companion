<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SocialAccountController;
use App\Http\Controllers\Web\TasteProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // {provider} resolves to the SocialProvider enum; an unknown value 404s
    // before it reaches the controller.
    Route::delete('settings/social/{provider}', [SocialAccountController::class, 'destroy'])
        ->name('social.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // "Reset my taste profile" (SCREENS S10): forget what you CONCLUDED about me.
    // Not "forget what I did" — the feedback ledger is the moat and survives this.
    Route::get('settings/taste', [TasteProfileController::class, 'edit'])->name('taste.edit');
    Route::delete('settings/taste', [TasteProfileController::class, 'destroy'])->name('taste.destroy');
});
