<?php

namespace App\Http\Controllers\Settings;

use App\Auth\Actions\DisconnectSocialAccount;
use App\Auth\Enums\SocialProvider;
use App\Auth\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function destroy(Request $request, SocialProvider $provider, DisconnectSocialAccount $disconnect): RedirectResponse
    {
        try {
            $disconnect->handle($request->user(), $provider);
        } catch (SocialAuthException $e) {
            return back()->withErrors(['provider' => $e->userMessage()]);
        }

        return back()->with('status', 'google-disconnected');
    }
}
