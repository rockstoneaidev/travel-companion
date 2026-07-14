<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\ExplainRecommendation;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S4 — opportunity detail (SCREENS.md): the stored recommendation trace is
 * the data; WHY YOU and EVIDENCE come from ExplainRecommendation. Thin
 * (conventions/04).
 *
 * ===========================================================================
 *  NOT EVERY OPPORTUNITY YOU CAN OPEN WAS RECOMMENDED TO YOU
 * ===========================================================================
 *
 * This used to require a `recommendations` row for (this opportunity, this user) and
 * `firstOrFail()` on its absence. That made a 404 out of the most-tapped image in the
 * product.
 *
 * The digest is built from the funnel's near-misses and held-back candidates — things
 * the ranker WEIGHED AND DID NOT SERVE (BuildDigest) — and the home screen renders them
 * as the hero, the "Also worth knowing" rows, and the dimmed map pins. By definition
 * none of them has a recommendation. So every clickable thing on the home screen led to
 * a 404, including the one big photograph the screen is built around.
 *
 * A recommendation is therefore OPTIONAL here, and its absence is a meaningful state
 * rather than an error: it means "I looked at this and passed it over". What the trace
 * carries — WHY YOU, EVIDENCE, the walk, and the right to hold an opinion about the item
 * — is shown only when the trace exists. The place itself is always shown, sourced from
 * `places_core` rather than from the trace's frozen candidate copy, because that is the
 * one source that is there either way.
 */
final class OpportunityController extends Controller
{
    public function show(Request $request, Opportunity $opportunity, ExplainRecommendation $explain): Response
    {
        $recommendation = Recommendation::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('user_id', $request->user()->id)
            ->latest('served_at')
            ->first();

        // Fetched here rather than through an `Opportunity::place()` relation: a module
        // may not reach into another module's models (conventions/01), and the Http layer
        // is where the two are allowed to meet.
        $place = Place::query()->find($opportunity->place_id);

        $image = PlaceImage::query()
            ->where('place_id', $opportunity->place_id)
            ->where('url', '!=', '')
            ->orderBy('id')
            ->first();

        return Inertia::render('opportunities/show', [
            'opportunity' => [
                'id' => $opportunity->id,
                'kind' => $opportunity->kind->value,
                'title' => $opportunity->title ?? $place?->name ?? 'Unnamed place',
                'summary' => $opportunity->summary,
            ],
            'place' => [
                'name' => $place?->name,
                'lat' => $place?->location?->lat,
                'lng' => $place?->location?->lng,
                'type' => $place?->type?->value,
                'facets' => $place?->facets?->map(static fn ($facet): string => $facet->value)->all() ?? [],
            ],
            'image' => $image === null ? null : [
                'url' => $image->url,
                'attribution' => $image->attribution,
                'license' => $image->license,
            ],

            // Null when the ranker weighed this and held it back: there is no trace to
            // explain, and nothing for an opinion to attach itself to.
            'recommendation' => $recommendation === null ? null : [
                'id' => $recommendation->id,
                'travel_minutes' => $recommendation->score_inputs['reachability']['travel_min'] ?? null,
                // The mode the time was measured in. Without it the detail screen was
                // rendering "min walk" over a number that, in a driving session, is a
                // drive — the number was right and the noun was a lie.
                'travel_mode' => ExploreSession::query()
                    ->whereKey($recommendation->explore_session_id)
                    ->value('travel_mode') ?? TravelMode::Walk->value,
            ],
            'explanation' => $recommendation === null ? null : $explain($recommendation),
            'sessionId' => $recommendation?->explore_session_id,
        ]);
    }
}
