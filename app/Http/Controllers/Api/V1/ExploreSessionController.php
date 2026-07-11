<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\StartExploreSession;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExploreSessions\StoreExploreSessionRequest;
use App\Http\Resources\Api\V1\ExploreSessionResource;

final class ExploreSessionController extends Controller
{
    public function store(StoreExploreSessionRequest $request, StartExploreSession $startExploreSession): ExploreSessionResource
    {
        $session = $startExploreSession($request->toData());

        return new ExploreSessionResource($session->load('trip'));
    }

    public function show(ExploreSession $exploreSession): ExploreSessionResource
    {
        return new ExploreSessionResource($exploreSession->load('trip'));
    }
}
