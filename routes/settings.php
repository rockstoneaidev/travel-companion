<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SocialAccountController;
use App\Http\Controllers\Web\PrivacyController;
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

    /*
    | Privacy (PRD §16, E17): the home zone, research consent, export, erasure.
    | Everything here is the user acting on their own data — a right, not a feature.
    */
    Route::get('settings/privacy', [PrivacyController::class, 'edit'])->name('privacy.edit');
    Route::put('settings/privacy/home-zone', [PrivacyController::class, 'updateHomeZone'])->name('privacy.home-zone.update');
    Route::delete('settings/privacy/home-zone', [PrivacyController::class, 'forgetHomeZone'])->name('privacy.home-zone.forget');
    Route::put('settings/privacy/profiling-consent', [PrivacyController::class, 'updateProfilingConsent'])->name('privacy.profiling-consent');
    Route::put('settings/privacy/research-consent', [PrivacyController::class, 'updateResearchConsent'])->name('privacy.research-consent');
    Route::get('settings/privacy/export', [PrivacyController::class, 'export'])->name('privacy.export');
    Route::delete('settings/privacy/account', [PrivacyController::class, 'destroy'])->name('privacy.account.destroy');

    // "Reset my taste profile" (SCREENS S10): forget what you CONCLUDED about me.
    // Not "forget what I did" — the feedback ledger is the moat and survives this.
    Route::get('settings/taste', [TasteProfileController::class, 'edit'])->name('taste.edit');
    Route::delete('settings/taste', [TasteProfileController::class, 'destroy'])->name('taste.destroy');
});
