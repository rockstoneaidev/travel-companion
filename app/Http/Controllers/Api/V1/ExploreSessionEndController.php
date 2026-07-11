<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\EndExploreSession;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExploreSessionResource;

/**
 * `POST /api/v1/explore-sessions/{session}/end`. A separate controller because
 * "end" is not CRUD on the session — it is a resource of its own being created
 * (conventions/04).
 */
final class ExploreSessionEndController extends Controller
{
    public function store(ExploreSession $exploreSession, EndExploreSession $endExploreSession): ExploreSessionResource
    {
        return new ExploreSessionResource($endExploreSession($exploreSession)->load('trip'));
    }
}
