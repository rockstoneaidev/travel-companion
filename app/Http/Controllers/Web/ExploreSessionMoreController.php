<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Actions\ExtendSessionFeed;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/** "Show more" (S1) — the Inertia twin of the API route. Ownership gated on the route. */
final class ExploreSessionMoreController extends Controller
{
    public function store(ExploreSession $exploreSession, ExtendSessionFeed $extend): RedirectResponse
    {
        $extend(ExploreSessionData::fromModel($exploreSession));

        // Back to the feed, which now shows the extended batch. A "show more" that found
        // nothing new is not an error — the screen already shows the best there is.
        return to_route('explore.show', $exploreSession);
    }
}
