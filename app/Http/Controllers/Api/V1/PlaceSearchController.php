<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Places\Data\PlaceSuggestionData;
use App\Domain\Places\Services\PlaceTypeahead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Places\SearchPlacesRequest;
use Illuminate\Http\JsonResponse;

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
