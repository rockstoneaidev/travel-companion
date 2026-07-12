<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Places\Contracts\PlaceLookup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Places\SearchPlacesRequest;
use App\Http\Resources\Api\V1\PlaceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlaceSearchController extends Controller
{
    public function index(SearchPlacesRequest $request, PlaceLookup $places): AnonymousResourceCollection
    {
        return PlaceResource::collection(
            $places->search($request->searchTerm(), $request->limit()),
        );
    }
}
