<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Queries\BuildDigest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S8 — the daily digest (PRD §12.4). A screen you FIND, not a tap on the
 * shoulder: Phase 1 is pull-only and there is no push (PRD §8).
 */
final class DigestController extends Controller
{
    public function today(Request $request, BuildDigest $digest): Response
    {
        $data = $digest->forUser((int) $request->user()->id);

        return Inertia::render('digest', [
            'digest' => [
                'variant' => $data->variant,
                'lede' => $data->lede,
                'subline' => $data->subline,
                'trip_id' => $data->tripId,
                'trip_name' => $data->tripName,
                'visited_today' => $data->visitedToday,
                'kept_today' => $data->keptToday,
                'items' => array_map(static fn ($item): array => [
                    'opportunity_id' => $item->opportunityId,
                    'title' => $item->title,
                    'note' => $item->note,
                    'window_ends_at' => $item->windowEndsAt?->toIso8601String(),
                    'image' => $item->image,
                ], $data->items),
            ],
        ]);
    }
}
