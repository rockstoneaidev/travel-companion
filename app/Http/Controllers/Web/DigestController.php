<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Data\DigestItem;
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
                'items' => array_map(self::item(...), $data->items),
            ],
        ]);
    }

    /**
     * The digest, drawn as geography (S8 → the map it always promised).
     *
     * "Save any to today's map" said the footer, and the link under it went to `/map` —
     * which resolves the ACTIVE SESSION's map, and there isn't one, because the digest is
     * a screen you read over breakfast. So it fell through to the session start form, and
     * the founder reasonably read that as "clicking the map started a new session".
     *
     * The digest's map is the digest's places. It needs no session, and asking someone to
     * declare "I have three hours" before they may look at a map is the app demanding a
     * commitment in exchange for information.
     */
    public function map(Request $request, BuildDigest $digest): Response
    {
        $data = $digest->forUser((int) $request->user()->id);

        return Inertia::render('digest-map', [
            'lede' => $data->lede,
            // Only what can actually be drawn. An item with no coordinate is not a pin, and
            // a pin at (0,0) is a lie in the Gulf of Guinea.
            'items' => array_values(array_map(
                self::item(...),
                array_filter($data->items, static fn ($item): bool => $item->lat !== null && $item->lng !== null),
            )),
        ]);
    }

    /** @return array<string, mixed> */
    private static function item(DigestItem $item): array
    {
        return [
            'opportunity_id' => $item->opportunityId,
            'title' => $item->title,
            'note' => $item->note,
            'window_ends_at' => $item->windowEndsAt?->toIso8601String(),
            'image' => $item->image,
            'lat' => $item->lat,
            'lng' => $item->lng,
        ];
    }
}
