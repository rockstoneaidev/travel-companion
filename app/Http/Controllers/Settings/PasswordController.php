<?php

namespace App\Http\Controllers\Settings;

use App\Auth\Models\SocialAccount;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Drives "Set a password" vs "Update password" — a Google-created
            // account has no current password to confirm (E22).
            'hasPassword' => $user->hasPassword(),
            'socialAccounts' => $user->socialAccounts()
                ->get()
                ->map(fn (SocialAccount $account): array => [
                    'provider' => $account->provider->value,
                    'label' => $account->provider->label(),
                    'email' => $account->email,
                    'linked_at' => $account->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Update the user's password — or set the first one, for an account created
     * through Google.
     */
    public function update(Request $request): RedirectResponse
    {
        // A user with no password cannot prove one. Their possession of a live
        // session (established by Google, which verified the email) is the proof
        // we have, and it is the same proof a password reset link would give us.
        $validated = $request->validate([
            'current_password' => $request->user()->hasPassword()
                ? ['required', 'current_password']
                : ['missing'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // Rotating the remember token invalidates every other device's
        // long-lived login (logins are always remembered — LoginRequest);
        // changing your password is how a stolen device gets locked out.
        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // Re-issue this device's remember cookie so only the others drop.
        Auth::guard('web')->login($request->user(), remember: true);

        return back();
    }
}
