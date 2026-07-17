<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Places\Data\PlaceSuggestionData;
use App\Domain\Places\Services\PlaceTypeahead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Places\SearchPlacesRequest;
use Illuminate\Http\JsonResponse;

/**
 * The Inertia twin of Api\V1\PlaceSearchController — same Form Request, same service
 * (conventions/04).
 *
 * A typeahead is the one place the Inertia surface wants plain JSON rather than a page visit:
 * the S2 start form and the trip planner fetch suggestions without navigating. It now searches
 * both `places_core` and the global gazetteer (PlaceTypeahead), so any settlement on Earth can
 * be a starting point, not only the places we have already ingested.
 */
final class PlaceSearchController extends Controller
{
    public function index(SearchPlacesRequest $request, PlaceTypeahead $typeahead): JsonResponse
    {
        $suggestions = $typeahead->search($request->searchTerm(), $request->limit());

        return new JsonResponse([
            'data' => array_map(static fn (PlaceSuggestionData $suggestion): array => $suggestion->toArray(), $suggestions),
        ]);
    }
}
