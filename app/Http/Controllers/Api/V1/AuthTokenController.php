<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Email + password → a Sanctum token, for the mobile client (E33).
 *
 * The web app authenticates by session cookie (Inertia); the native app cannot, so it
 * trades credentials for a bearer token here, ONCE, and uses it thereafter. This is the
 * only endpoint the mobile client hits without a token — everything else in /api/v1 is
 * behind `auth:sanctum`.
 *
 * ## The security shape is deliberate
 *
 * - **Rate-limited hard** (the route). Credential-stuffing a token endpoint is the classic
 *   attack, and a bearer-token mint with no throttle is an open door.
 * - **Generic failure.** "These credentials do not match" — never "no such user" vs "wrong
 *   password". A login endpoint that distinguishes them is a username oracle.
 * - **Named tokens, so they can be revoked one device at a time.** A lost phone is a
 *   `DELETE /devices/{id}` and a token revocation, not a password reset for the account.
 */
final class AuthTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],   // "Mats's iPhone" — names the token
        ]);

        if (! Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            // One message for every failure mode — never a username oracle.
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = Auth::getProvider()->retrieveByCredentials([
            'email' => $credentials['email'],
        ]);

        // The token's abilities are the whole API for now; when the mobile client needs
        // less (a read-only companion mode, say), this is where that narrows.
        $token = $user->createToken($credentials['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /** Sign this device out — revoke the token it presented. */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['revoked' => true]);
    }
}
