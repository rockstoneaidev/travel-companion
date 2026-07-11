<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Context\Actions\RecordContextEvent;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExploreSessions\StoreContextEventRequest;
use App\Http\Resources\Api\V1\ContextEventResource;
use Illuminate\Http\JsonResponse;

/** `POST /api/v1/explore-sessions/{session}/context-events` (PRD §14.5). */
final class ExploreSessionContextEventController extends Controller
{
    /**
     * `$exploreSession` is unused here on purpose: the type-hint is what makes
     * SubstituteBindings resolve the route parameter into a model, which is what
     * the Form Request's authorize() and toData() read. Without it the request
     * sees a bare string and every call 403s.
     */
    public function store(
        StoreContextEventRequest $request,
        ExploreSession $exploreSession,
        RecordContextEvent $recordContextEvent,
    ): JsonResponse {
        $event = $recordContextEvent($request->toData());

        return (new ContextEventResource($event))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
