<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Actions\RefreshSessionFeed;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/** "Fresh picks from here" (SCREENS S1, E46) — the Inertia twin of the API route. */
final class ExploreSessionRefreshController extends Controller
{
    /** Ownership is gated by `->can('update', 'exploreSession')` on the route (conventions/04). */
    public function store(ExploreSession $exploreSession, RefreshSessionFeed $refresh): RedirectResponse
    {
        $refresh(ExploreSessionData::fromModel($exploreSession));

        /*
         * Back to the feed either way, including when nothing was re-served.
         *
         * A refresh that finds nothing new is not an error and must not read like one:
         * the honest outcome is "these are still the best things near you", and the
         * screen already says that by showing them. Flashing a failure at someone
         * because the neighbourhood has not changed in ninety seconds would be the app
         * apologising for being right.
         */
        return to_route('explore.show', $exploreSession);
    }
}
