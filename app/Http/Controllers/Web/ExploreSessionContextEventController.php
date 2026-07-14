<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Context\Actions\RecordContextEvent;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExploreSessions\StoreContextEventRequest;
use Illuminate\Http\JsonResponse;

/**
 * The Inertia twin of Api\V1\ExploreSessionContextEventController — same Form
 * Request, same action (conventions/04).
 *
 * It exists because the PWA authenticates with the session cookie, not a Sanctum
 * token, and because the client posts this the same way it posts feedback: a bare
 * `fetch` with the XSRF header, outside Inertia's navigation cycle. A position
 * report must not be a page visit — it happens on mount and on focus, and turning
 * every one of those into a navigation would fight the router for no reason.
 *
 * Returns 204, deliberately. The client does not want the event back; what it wants
 * is the FEED that this event may have just re-anchored, and it fetches that with an
 * Inertia reload immediately afterwards.
 */
final class ExploreSessionContextEventController extends Controller
{
    /**
     * `$exploreSession` is unused here on purpose: the type-hint is what makes
     * SubstituteBindings resolve the route parameter into a model, which is what the
     * Form Request's authorize() and toData() read. Without it the request sees a
     * bare string and every call 403s.
     */
    public function store(
        StoreContextEventRequest $request,
        ExploreSession $exploreSession,
        RecordContextEvent $recordContextEvent,
    ): JsonResponse {
        $recordContextEvent($request->toData());

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
