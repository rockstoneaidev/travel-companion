<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Places\Contracts\PlaceLookup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Places\SearchPlacesRequest;
use App\Http\Resources\Api\V1\PlaceResource;
use Illuminate\Http\JsonResponse;

/**
 * The Inertia twin of Api\V1\PlaceSearchController — same Form Request, same
 * Query, same Resource (conventions/04).
 *
 * A typeahead is the one place the Inertia surface wants plain JSON rather than
 * a page visit: the S2 start form fetches suggestions without navigating.
 */
final class PlaceSearchController extends Controller
{
    public function index(SearchPlacesRequest $request, PlaceLookup $places): JsonResponse
    {
        $results = $places->search($request->searchTerm(), $request->limit());

        return new JsonResponse([
            'data' => PlaceResource::collection($results)->resolve(),
        ]);
    }
}
