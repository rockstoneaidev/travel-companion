<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Show me everything." (E51.)
 *
 * ## Why this screen exists
 *
 * The feed is five cards, and five is the right number for the thing the feed IS: the
 * interruption budget — how many things are worth putting in front of somebody who did not
 * ask. It is the wrong number for "what is around me", and using one number for both makes
 * the product an authority it has not earned. The founder put it better than I would:
 *
 *   > *"Just showing 5 is a supremacy thing that makes the system be the authority it is
 *   > not. Gimme more!"*
 *
 * He is right, and the fix is nearly free, which is the embarrassing part. The pipeline was
 * already scoring every reachable candidate and throwing all but five away. This screen is
 * the work we were already doing.
 *
 * ## The cost discipline that makes it affordable
 *
 * A browse item is a SCORED CANDIDATE, not an opportunity: no row, no LLM voice, no Google
 * call. A hundred rows of generated copy for a list somebody is scrolling past would be the
 * most expensive possible way to be ignored.
 *
 * The money is spent on the one they OPEN — `RankSession::open()` mints the opportunity and
 * the recommendation at that moment, so the trace, the "why did I get this", and keep and
 * dismiss all work exactly as they do from the feed.
 */
final class BrowseController extends Controller
{
    public function index(
        Request $request,
        ExploreSession $exploreSession,
        RankSession $rank,
        PlaceImageLookup $images,
    ): Response {
        $perPage = (int) config('trips.session.browse_page_size');

        $limit = min(
            (int) config('trips.session.browse_max'),
            max($perPage, (int) $request->integer('limit', $perPage)),
        );

        $browse = $rank->browse(ExploreSessionData::fromModel($exploreSession), $limit);

        $imagesByPlace = $images->forPlaces(array_column($browse['items'], 'place_id'));

        return Inertia::render('explore/browse', [
            'session' => ['id' => $exploreSession->id, 'travel_mode' => $exploreSession->travel_mode->value],
            'total' => $browse['total'],
            'limit' => $limit,
            'items' => array_map(static fn (array $c): array => [
                'place_id' => $c['place_id'],
                'name' => $c['name'],
                'type' => $c['type'],
                'type_domain' => $c['type_domain'],
                'travel_minutes' => (int) round((float) $c['reachability']['travel_min']),
                // The score, shown. A ranked list that will not say what it ranked ON is
                // just a different flavour of "trust me".
                'score' => round((float) $c['composite'], 3),
                'why' => self::why($c['sub_scores']),
                'image' => $imagesByPlace[$c['place_id']] ?? null,
                'lat' => (float) $c['lat'],
                'lng' => (float) $c['lng'],
            ], $browse['items']),
        ]);
    }

    /** They chose one. Make it real, then show it to them. */
    public function open(ExploreSession $exploreSession, string $placeId, RankSession $rank): RedirectResponse
    {
        $recommendation = $rank->open(ExploreSessionData::fromModel($exploreSession), $placeId);

        if ($recommendation === null) {
            return back()->with('error', 'That one has slipped out of reach.');
        }

        return redirect()->route('opportunities.show', $recommendation->opportunity_id);
    }

    /**
     * The strongest thing we can say about this place, in one word.
     *
     * Not a summary — a summary would need the LLM, and that is exactly the cost this
     * screen refuses to pay a hundred times over. It is the highest sub-score, named. That
     * is honest, it is free, and it tells the user what the ranking actually keyed on.
     *
     * @param  array<string, float>  $subScores
     */
    private static function why(array $subScores): string
    {
        $labels = [
            'personal_fit' => 'Your kind of thing',
            'uniqueness' => 'Unusual',
            'temporal_urgency' => 'Time-sensitive',
            'novelty' => 'New to you',
            'route_fit' => 'On your way',
            'confidence' => 'Well attested',
            'context_fit' => 'Fits right now',
        ];

        $best = null;
        $bestScore = -1.0;

        foreach ($subScores as $key => $value) {
            if (isset($labels[$key]) && (float) $value > $bestScore) {
                $best = $key;
                $bestScore = (float) $value;
            }
        }

        return $best === null ? 'Nearby' : $labels[$best];
    }
}
