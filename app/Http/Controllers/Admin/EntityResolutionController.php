<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Places\Actions\DecideReviewPair;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use App\Domain\Places\Queries\ReviewQueue;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The entity-resolution review queue (ADMIN.md; ENTITY-RESOLUTION §3 stage 4).
 * Thin over the Places domain — no resolution logic here.
 */
final class EntityResolutionController extends Controller
{
    public function index(ReviewQueue $queue): Response
    {
        return Inertia::render('admin/entity-resolution', [
            'pairs' => array_map(static fn ($pair): array => [
                'decision_id' => $pair->decisionId,
                'candidate' => ['id' => $pair->candidatePlaceId, 'name' => $pair->candidatePlaceName, 'source' => $pair->candidateSource],
                'compared' => ['id' => $pair->comparedPlaceId, 'name' => $pair->comparedPlaceName, 'source' => $pair->comparedSource],
                'score' => $pair->score,
                'distance_meters' => $pair->distanceMeters,
                'signals' => $pair->signals,
            ], $queue->pending()),
            'pendingCount' => $queue->pendingCount(),
            'resolverVersion' => (string) config('resolver.version'),
        ]);
    }

    public function merge(Request $request, PlaceMatchDecision $decision, DecideReviewPair $decide): RedirectResponse
    {
        $validated = $request->validate([
            'candidate_place_id' => ['required', 'uuid'],
            'compared_place_id' => ['required', 'uuid', 'different:candidate_place_id'],
        ]);

        $decide->merge(
            $decision,
            Place::query()->findOrFail($validated['candidate_place_id']),
            Place::query()->findOrFail($validated['compared_place_id']),
        );

        return back()->with('status', 'Merged.');
    }

    public function keepDistinct(PlaceMatchDecision $decision, DecideReviewPair $decide): RedirectResponse
    {
        $decide->keepDistinct($decision);

        return back()->with('status', 'Kept as separate places.');
    }
}
