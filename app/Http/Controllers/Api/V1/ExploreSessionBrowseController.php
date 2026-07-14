<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Show me everything around me" on the API (E51 + E33).
 *
 * The web got this screen in E51; the mobile client gets the same data here, from the same
 * `RankSession::browse()` — the point of the API-first boundary. Looking is free (a scored
 * candidate, no opportunity row, no LLM); opening spends, via the feedback/opportunity path.
 */
final class ExploreSessionBrowseController extends Controller
{
    public function index(Request $request, ExploreSession $exploreSession, RankSession $rank, PlaceImageLookup $images): JsonResponse
    {
        $limit = min(
            (int) config('trips.session.browse_max'),
            max((int) config('trips.session.browse_page_size'), (int) $request->integer('limit', (int) config('trips.session.browse_page_size'))),
        );

        $browse = $rank->browse(ExploreSessionData::fromModel($exploreSession), $limit, (int) $request->integer('offset'));
        $imagesByPlace = $images->forPlaces(array_column($browse['items'], 'place_id'));

        return response()->json([
            'total' => $browse['total'],
            'items' => array_map(static fn (array $c): array => [
                'place_id' => $c['place_id'],
                'name' => $c['name'],
                'type' => $c['type'],
                'type_domain' => $c['type_domain'],
                'travel_minutes' => (int) round((float) $c['reachability']['travel_min']),
                'score' => round((float) $c['composite'], 3),
                'image' => $imagesByPlace[$c['place_id']] ?? null,
                'lat' => (float) $c['lat'],
                'lng' => (float) $c['lng'],
            ], $browse['items']),
        ]);
    }
}
