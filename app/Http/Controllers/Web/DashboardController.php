<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Queries\BuildDigest;
use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Domain\Trips\Queries\FindActiveExploreSessionForUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home — "today" (PRD §12.4).
 *
 * It was the starter kit's empty skeleton, and the temptation was to fill it with
 * "nearby things + a map". That would just be Explore at a second URL, and
 * duplicating the primary surface is how an app stops making sense.
 *
 * The digest is the right spine and it was already built and ORPHANED — nothing in
 * the app linked to /digest/today. PRD §12.4 calls it the daily habit surface: the
 * release valve that makes the feed's silence affordable. So home is: what's worth
 * knowing today, where you left off, and what you kept.
 *
 * Deliberately NOT a map of every place we know about. That would be a different
 * product — Google Maps with our pins — and it would undo the only promise this one
 * makes. The map belongs to a session, where it has a reason to exist (S3).
 */
final class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDigest $digest,
        ListKeptForUser $kept,
        FindActiveExploreSessionForUser $findActiveSession,
    ): Response {
        $userId = (int) $request->user()->id;
        $data = $digest->forUser($userId);

        $keptItems = $kept->forUser($userId);

        return Inertia::render('dashboard', [
            'digest' => [
                'variant' => $data->variant,
                'lede' => $data->lede,
                'subline' => $data->subline,
                'items' => array_map(static fn ($item): array => [
                    'opportunity_id' => $item->opportunityId,
                    'title' => $item->title,
                    'note' => $item->note,
                ], array_slice($data->items, 0, 3)),
            ],

            // Where you left off. A session already open is the single most useful
            // thing this screen can offer, and it was offering nothing.
            'session' => $findActiveSession($userId)?->id,

            'kept' => [
                'still_possible' => count(array_filter($keptItems, static fn ($item): bool => $item->stillPossible)),
                'total' => count($keptItems),
            ],
        ]);
    }
}
