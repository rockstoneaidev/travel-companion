<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Data\DigestItem;
use App\Domain\Recommendations\Data\KeptItemData;
use App\Domain\Recommendations\Queries\BuildDigest;
use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Domain\Trips\Queries\FindActiveExploreSessionForUser;
use App\Domain\Trips\Queries\FindLastKnownOriginForUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home — "today" (PRD §12.4).
 *
 * It was the starter kit's empty skeleton, and the first temptation was to fill it
 * with "nearby things + a map of everything". That would be Google Maps with our
 * pins: it hands the choosing back to the user, which is the one job this product
 * claims to do for them. The digest is the right spine — what's worth knowing
 * today, where you left off, what you kept.
 *
 * ===========================================================================
 *  WHY THIS SCREEN IS VISUAL, AND WHY THE MAP IS THE PICTURE
 * ===========================================================================
 *
 * The screen was honest and dull: a lede, a button, a count. The fix people reach
 * for is "more photographs", and the world model cannot pay for it — 1,479 of
 * 53,133 places carry an image (2.8%), and even among our APPROVED curated items
 * only 25 of 98 do. A photo grid would be three-quarters empty boxes.
 *
 * But every place has a LOCATION. Geography is the only picture we can always draw.
 * So the map is not an extra widget here; it is the screen's imagery, and the
 * photographs are the accent on top of it.
 *
 * What the map shows is a product decision, not a technical one:
 *
 *   · YOU — the origin you last nominated (never a silent background fix; PRD §8).
 *   · KEPT, still possible — solid pins. Things you already said yes to.
 *   · PASSED OVER — dimmed pins. The ones the ranker weighed and held back. The
 *     digest already calls them "what I passed over", so they are not a secret;
 *     showing them on the map is the same disclosure in the medium where it means
 *     something.
 *
 * And NOT the other 53,000 places we know about. That map is a different product.
 * The pins here are the ones the system has an opinion about.
 *
 * The evening state is the one that looked broken — an all-but-empty page at 23:15
 * saying "Nothing needs deciding tonight". The words are right; inventing urgency at
 * midnight would be the whole product lying. But silence is not the same as a blank
 * page, so evening keeps the map: no nudge, no urgency, just where you are and what
 * is still standing around you. Orientation is not an interruption.
 */
final class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDigest $digest,
        ListKeptForUser $kept,
        FindActiveExploreSessionForUser $findActiveSession,
        FindLastKnownOriginForUser $lastKnownOrigin,
    ): Response {
        $userId = (int) $request->user()->id;
        $data = $digest->forUser($userId);

        $keptItems = $kept->forUser($userId);
        $stillPossible = array_values(array_filter(
            $keptItems,
            static fn (KeptItemData $item): bool => $item->stillPossible,
        ));

        $located = array_values(array_filter(
            $data->items,
            static fn (DigestItem $item): bool => $item->lat !== null && $item->lng !== null,
        ));

        return Inertia::render('dashboard', [
            'digest' => [
                'variant' => $data->variant,
                'lede' => $data->lede,
                'subline' => $data->subline,
                'items' => array_map(self::digestItem(...), array_slice($data->items, 0, 3)),
            ],

            // The one thing worth looking at, big. Choosing the item that HAS a
            // photograph over the one that actually ranks first would be dressing the
            // window: the hero is the top item either way, and with no picture it falls
            // back to the designed paper-stripe rather than a stock photo of somewhere
            // that is not this place.
            'hero' => isset($data->items[0]) ? self::digestItem($data->items[0]) : null,

            // Where you left off. A session already open is the single most useful
            // thing this screen can offer, and it was offering nothing.
            'session' => $findActiveSession($userId)?->id,

            'map' => [
                'origin' => $lastKnownOrigin($userId),
                'pins' => [
                    ...array_map(static fn (KeptItemData $item): array => [
                        'id' => $item->recommendationId,
                        'lat' => $item->lat,
                        'lng' => $item->lng,
                        'label' => $item->title,
                        'dimmed' => false,      // you said yes to this one
                        'href' => $item->opportunityId === null ? null : "/opportunities/{$item->opportunityId}",
                    ], $stillPossible),

                    ...array_map(static fn (DigestItem $item): array => [
                        'id' => $item->opportunityId,
                        'lat' => $item->lat,
                        'lng' => $item->lng,
                        'label' => $item->title,
                        'dimmed' => true,       // weighed, held back — visible, not shouted
                        'href' => "/opportunities/{$item->opportunityId}",
                    ], $located),
                ],
            ],

            'kept' => [
                'still_possible' => count($stillPossible),
                'total' => count($keptItems),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function digestItem(DigestItem $item): array
    {
        return [
            'opportunity_id' => $item->opportunityId,
            'title' => $item->title,
            'note' => $item->note,
            'image' => $item->image,
        ];
    }
}
