<?php

declare(strict_types=1);

namespace App\Domain\Sources\Actions;

use App\Domain\Places\Services\PlaceDensity;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\DerivedRegion;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Domain\Sources\Services\RegionCatalog;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Jobs\Ingest\FirstLightJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Somebody just walked into a place we have never heard of. Go and learn it." (E48.)
 *
 * The world model was a hand-reviewed catalogue — Stockholm and seven French cities —
 * and everywhere else was silence. The founder dropped a pin in Skellefteå, a town of
 * 35,000, and the app had nothing to say, because Skellefteå was not in a PHP file.
 *
 * VISION §1 always claimed the global path existed ("scouts fetch the current tile on
 * demand… it needs no region catalog at all"). It did not: every scout reads our own
 * `places` table, so they can only find what bulk ingest already put there. This is the
 * missing half — the thing that PUTS it there, triggered by the only signal that
 * actually means anything: a real person, actually going somewhere.
 */
final class LearnAreaIfUnknown
{
    /**
     * How many regions one person may cause us to learn in a day.
     *
     * Not paranoia about abuse — registration is allowlisted. It is a bound on a
     * pathological client: a loop dropping pins across a continent would otherwise queue
     * a thousand Overpass boxes and make us exactly the kind of citizen that gets an IP
     * banned from a free, community-run API.
     */
    private const MAX_REGIONS_PER_USER_PER_DAY = 6;

    public function __construct(
        private readonly PlaceDensity $places,
        private readonly DeriveRegionForPosition $derive,
        private readonly RegionBuildStatus $status,
        private readonly RegionCatalog $catalog,
    ) {}

    /**
     * @return bool whether a build was started (false = we already know here, or we are
     *              already learning it, or this user has asked enough for one day)
     */
    public function __invoke(float $lat, float $lng, int $reachMeters, ?int $userId = null): bool
    {
        /*
         * DOES A REGION ALREADY CLAIM THIS GROUND? Ask FIRST, before looking at places.
         *
         * Getting the order wrong is not a nuance, it is a self-inflicted denial of
         * service: a session opened in Stockholm on a day the feed happens to be thin
         * would see "no places within reach", conclude the area was unknown, and set the
         * whole 584 km² region ingesting again. The tests caught it doing exactly that —
         * every session in a test database with an empty `places` table queued a full
         * Stockholm re-ingest.
         *
         * A region we already have is a promise we already kept. Whether today's feed is
         * empty is a question about ranking, not about coverage.
         */
        $claimed = $this->catalog->covering($lat, $lng);

        if ($claimed !== null) {
            /*
             * ...unless the claim was never honoured.
             *
             * A region row is a promise. `derived_regions` gets one the moment somebody
             * walks into unknown ground, and from then on this guard refuses to look at
             * that ground again — which is correct when the build worked, and a PERMANENT
             * DEAD ZONE when it didn't.
             *
             * It didn't, twice. Umeå and Skellefteå were claimed, their builds died on the
             * old region-key scheme, and both towns then sat there with a row saying "we
             * know this place" and a database saying nothing at all. Ten hours of "Nothing
             * worth interrupting you for" over a city of 90,000 people, with no way back:
             * the claim blocked the retry that would have fixed it.
             *
             * So the guard now asks the honest question. Not "did somebody promise to learn
             * this?" but "do we actually know anything here?" A claim with no places behind
             * it is not coverage, it is a scar — and the cure is to try again.
             */
            if ($this->places->within($lat, $lng, $reachMeters) > 0) {
                return false;
            }

            // Being learned right now? Then wait for it — `isBuilding()` already knows that a
            // stalled claim is a corpse holding the door, not a build.
            if ($this->status->isBuilding($claimed->key)) {
                return false;
            }

            Log::warning('A region claimed this ground and never delivered it. Learning it again.', [
                'region' => $claimed->key, 'name' => $claimed->name,
            ]);

            return $this->build($claimed, $lat, $lng);
        }

        /*
         * ...and even with no region claiming it, actual places within reach mean this is
         * not virgin ground (a neighbouring region's box may overlap it). The feed's
         * silence there is a judgement rather than an absence — leave it alone.
         */
        if ($this->places->within($lat, $lng, $reachMeters) > 0) {
            return false;
        }

        if ($userId !== null && $this->askedTooMuch($userId)) {
            return false;
        }

        $region = ($this->derive)($lat, $lng, $userId);

        Log::info('Learning a region nobody had asked for before.', [
            'region' => $region->key, 'name' => $region->name, 'user_id' => $userId,
        ]);

        return $this->build($region, $lat, $lng);
    }

    /**
     * Start learning: first light now, the whole region behind it.
     */
    private function build(IngestRegion $region, float $lat, float $lng): bool
    {
        // Already building. `RegionBuildStatus::start()` is the lock, and it is the same
        // one the admin console's Build button takes — an operator and a traveller cannot
        // race each other into ingesting the same city twice.
        if (! $this->status->start($region->key)) {
            return false;
        }

        try {
            /*
             * FIRST LIGHT, before anything else.
             *
             * One small box around the person, on a lane that is not about to be full of
             * this region's fifty boxes. Overpass answers central Umeå in under four
             * seconds — the data was never slow, our shape was. The feed comes alive with
             * the places they can actually walk to while the full region fills in behind.
             *
             * Nothing it writes is provisional. It is the same ingest, run on the ground
             * they are standing on, FIRST.
             */
            FirstLightJob::dispatch($region->key, $lat, $lng);

            // The pin travels with the job: boxes are ingested NEAREST-FIRST, so the tiles
            // this person is standing in land early and the feed keeps thickening while the
            // rest of the region fills in behind them.
            BuildRegionWorldModelJob::dispatch(
                regionKey: $region->key,
                nearLat: $lat,
                nearLng: $lng,
            );
        } catch (Throwable $e) {
            /*
             * A region we could not queue is a DISAPPOINTMENT, not an outage.
             *
             * This runs off the back of "I have 3 hours" — a traveller opening a session.
             * If the ingest queue is unreachable, the session must still open and the feed
             * must still work (empty, and honest about why). Letting a broken queue take
             * down session creation would trade a missing region for a missing product.
             *
             * The build lock is released, so the next person through the door may try
             * again rather than inheriting a region that is permanently "building".
             */
            $this->status->finish($region->key);

            report($e);

            return false;
        }

        return true;
    }

    private function askedTooMuch(int $userId): bool
    {
        return DerivedRegion::query()
            ->where('requested_by_user_id', $userId)
            ->where('requested_at', '>=', now()->subDay())
            ->count() >= self::MAX_REGIONS_PER_USER_PER_DAY;
    }
}
