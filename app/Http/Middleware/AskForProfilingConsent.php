<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ask ONCE (Art. 9(2)(a), Art. 4(11)).
 *
 * Existing accounts were created before consent existed, so they have never been
 * asked — and until they are, their taste profile silently stops learning. They
 * deserve the question.
 *
 * But exactly once. Redirecting people to the consent screen on every page load
 * until they agree is nagging, and nagging invalidates the consent it extracts:
 * consent must be freely given, and a choice you are shown repeatedly until you pick
 * the right answer is not free. So we record that we asked, and a person who said no
 * is never asked again — they can turn it on themselves in Settings → Privacy, on
 * their own initiative, which is the only version of "yes" worth having.
 */
final class AskForProfilingConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $row = DB::table('users')
            ->where('id', $user->id)
            ->first(['profiling_consent_at', 'profiling_consent_asked_at']);

        $neverAsked = $row?->profiling_consent_asked_at === null && $row?->profiling_consent_at === null;

        return $neverAsked ? redirect()->route('calibrate.welcome') : $next($request);
    }
}
