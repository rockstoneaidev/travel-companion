<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Trips\Contracts\ExploreSessionLookup;
use App\Domain\Trips\Models\ExploreSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tell the cost meter when it is watching an operator, not a traveller (ADMIN §6, §2.4).
 *
 * `MeterCost` has read `$request->attributes->get('context_source')` since the cost
 * epic landed, and `CostActorKind::AdminEmulated` has existed just as long. **Nothing
 * has ever set the attribute.** The emulated branch was dead code waiting for the
 * emulator, and until now every request — including founder testing, which is most of
 * the traffic today — metered as real user spend.
 *
 * This is the thing that sets it. It must run BEFORE `MeterCost` (see bootstrap/app.php),
 * because MeterCost decides the actor in `handle()`, not on the way out.
 *
 * Two ways a request is emulated:
 *
 *  1. It concerns an emulated SESSION — the feed, the context events, the refresh. The
 *     session is the root of provenance, so the answer is a property of the row, not a
 *     guess about the caller.
 *  2. It is the emulator console itself, which spends real money ranking from a pin.
 *
 * Note what this does NOT do: tag every request an operator makes while an emulation is
 * running. An operator reading their own real feed in another tab is a user, and their
 * spend is a user's spend. Provenance follows the session, not the person.
 */
final class MarkEmulatedContext
{
    public function __construct(
        private readonly ExploreSessionLookup $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isEmulated($request)) {
            $request->attributes->set('context_source', 'emulated');
        }

        return $next($request);
    }

    private function isEmulated(Request $request): bool
    {
        // The console itself: ranking from a dropped pin costs what ranking costs.
        if ($request->is('admin/emulator', 'admin/emulator/*')) {
            return true;
        }

        $parameter = $request->route('exploreSession');

        // Group middleware may run before or after route-model binding depending on the
        // stack, so accept either shape rather than depending on the order.
        $id = match (true) {
            $parameter instanceof ExploreSession => $parameter->id,
            is_string($parameter) => $parameter,
            default => null,
        };

        if ($id === null) {
            return false;
        }

        return $this->sessions->find($id)?->contextSource->isReal() === false;
    }
}
