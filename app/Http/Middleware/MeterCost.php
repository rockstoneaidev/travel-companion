<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Cost\Services\CostLedger;
use App\Cost\Services\CostMeter;
use App\Enums\CostActorKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request-side flush seam (docs/COST.md §5).
 *
 * Terminable: the ledger is written AFTER the response has gone to the user. Cost
 * accounting must never sit between a traveller and their feed — the whole product is
 * a promise not to waste someone's attention, and spending 8ms of their latency to
 * write our own bookkeeping would be an odd way to keep it.
 *
 * Sets the correlation context from the authenticated request, so nothing deeper in
 * the stack has to thread a user id through six constructors to record a token count.
 * The Gemini client does not know who it is generating for, and should not have to.
 */
final class MeterCost
{
    public function handle(Request $request, Closure $next): Response
    {
        $meter = app(CostMeter::class);

        $user = $request->user();

        // An emulated position means an operator is driving the pipeline as if they
        // were somewhere they are not (ADMIN §6). Their spend is REAL — it shows in
        // the whole-bill strip — but it is not a user's usage, and every product
        // metric filters it out (ADMIN §2.4). Getting this wrong would quietly poison
        // cost-per-trip-hour with founder testing, which is most of the traffic today.
        $actor = $request->attributes->get('context_source') === 'emulated'
            ? CostActorKind::AdminEmulated
            : CostActorKind::User;

        $meter->actingAs($user !== null ? $actor : CostActorKind::System, $user?->id);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $meter = app(CostMeter::class);

        // CPU, not wall clock: wall time on a request that waited four seconds for
        // Google tells you about Google, not about the server we rent (COST.md §2.1).
        $usage = getrusage();
        $cpuMs = $usage === false ? 0 : (int) round(
            ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000
        );

        $meter->recordCompute(
            resource: 'request',
            cpuMs: $cpuMs,
            peakMemKb: (int) round(memory_get_peak_usage(true) / 1024),
        );

        app(CostLedger::class)->flush($meter);
    }
}
