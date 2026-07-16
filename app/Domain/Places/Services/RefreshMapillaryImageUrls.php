<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Support\Http\Harvest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keep Mapillary photo URLs alive (E50 fix).
 *
 * ## Why this has to exist, and Commons does not need it
 *
 * A Commons thumbnail URL is permanent. A Mapillary one is NOT: it is a signed
 * Facebook-CDN link (Meta owns Mapillary) carrying an `oe=` expiry — roughly four weeks
 * out. Store it raw and the photo works for a month and then 404s, which the founder would
 * see as an image that quietly turned back into a paper stripe.
 *
 * We already stored the one durable handle Mapillary gives — the image ID, in `file_name`
 * as `mapillary:<id>`. So this re-asks Mapillary for a fresh URL by that ID, before the old
 * one dies. The ID is stable; only the URL rots.
 *
 * ## Conservative about deletion
 *
 * A transport failure (Mapillary down, timeout) must NOT blank a good photo — it skips the
 * row and tries again next run. Only a clean response that genuinely has no image (the
 * Mapillary image was removed) clears the URL, so the place falls back to its stripe rather
 * than pointing at a dead link.
 */
final class RefreshMapillaryImageUrls
{
    private const API = 'https://graph.mapillary.com/';

    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    /** Refresh well before the ~4-week signed-URL expiry, so a photo never dies between runs. */
    private const REFRESH_AFTER_DAYS = 7;

    public function __construct(private readonly Harvest $harvest) {}

    /**
     * @return array{refreshed: int, cleared: int, skipped: int}
     */
    public function refreshBatch(int $limit = 200): array
    {
        $token = (string) config('services.mapillary.token');

        if ($token === '') {
            return ['refreshed' => 0, 'cleared' => 0, 'skipped' => 0];
        }

        $rows = DB::select(
            "SELECT id, file_name
             FROM place_images
             WHERE source = 'mapillary'
               AND url <> ''
               AND retrieved_at < ?
             ORDER BY retrieved_at
             LIMIT ?",
            [now()->subDays(self::REFRESH_AFTER_DAYS), $limit],
        );

        $refreshed = 0;
        $cleared = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $mapId = str_replace('mapillary:', '', (string) $row->file_name);

            try {
                $fresh = $this->freshUrl($mapId, $token);
            } catch (Throwable) {
                // Mapillary hiccup — leave the (possibly-still-valid) URL alone and retry
                // next run. Never blank a photo on a transport failure.
                $skipped++;

                continue;
            }

            if ($fresh === null) {
                // A clean answer with no image: the Mapillary shot was deleted. Clear the URL
                // so the place shows its honest stripe, not a dead link.
                DB::table('place_images')->where('id', $row->id)->update(['url' => '', 'retrieved_at' => now()]);
                $cleared++;

                continue;
            }

            DB::table('place_images')->where('id', $row->id)->update(['url' => $fresh, 'retrieved_at' => now()]);
            $refreshed++;
        }

        return ['refreshed' => $refreshed, 'cleared' => $cleared, 'skipped' => $skipped];
    }

    /** A fresh signed thumbnail URL for a Mapillary image id, or null if it no longer has one. */
    private function freshUrl(string $mapId, string $token): ?string
    {
        $url = $this->harvest->get(
            self::API.$mapId,
            ['fields' => 'thumb_1024_url', 'access_token' => $token],
            ['User-Agent' => self::USER_AGENT],
            timeout: 20,
        )->throwIfUnknown('mapillary image refresh')->json('thumb_1024_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
