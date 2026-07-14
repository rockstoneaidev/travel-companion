<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Sources\Data\ScoutRequest;
use App\Domain\Sources\Services\RegionCatalog;
use App\Domain\Sources\Services\RegionIngest;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FIRST LIGHT — something on the screen inside a minute (E48 follow-up).
 *
 * ## The bug this exists to kill
 *
 * `LearnAreaIfUnknown` did the right thing in the wrong ORDER. It claimed a whole res-5
 * region — 584 km², fifty-odd boxes — and queued the lot on the ingest lane, which is
 * `maxProcesses: 1` on purpose (public Overpass gives about two slots, and being rude to
 * it costs a city). So the traveller standing in the middle of Umeå waited *the better
 * part of an hour* for the first place to appear, and what they read on screen the whole
 * time was "Nothing worth interrupting you for."
 *
 * That sentence is a lie when the truth is "I have never heard of this town and I am
 * currently learning it". Nobody should ever see it for an hour. Nobody should see it for
 * a minute.
 *
 * ## What it does instead
 *
 * One small box, around the person, right now, on a lane that is not blocked behind the
 * region build. Measured against live Overpass: **central Umeå — 200 places in 3.7
 * seconds.** The data was never slow. Our shape was.
 *
 * So the feed comes alive in seconds with the handful of places you can actually walk to,
 * and the full region build keeps running behind it and *extends* what is there. First
 * light is not a downgraded ingest — it is the same ingest, run on the ground the user is
 * standing on, first. Nothing it writes has to be undone.
 *
 * ## Why it is a job and not inline in the request
 *
 * Four seconds is fine for a queue and far too long for the request that opens a session.
 * The session must open instantly, empty and honest ("learning this area"), and fill in
 * underneath — the client already polls for exactly this (E48's progress UI).
 */
final class FirstLightJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Roughly a 3 km box around the traveller: the ground a person could plausibly reach
     * on foot in the session they just opened, and small enough that Overpass answers in
     * seconds rather than minutes.
     *
     * Not tuned to be generous. Tuned to be FAST — generosity is the region build's job,
     * and it is already running.
     */
    private const HALF_SIDE_DEGREES_LAT = 0.014;   // ~1.55 km north and south

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public readonly string $regionKey,
        public readonly float $lat,
        public readonly float $lng,
    ) {
        /*
         * NOT the ingest lane. That lane is maxProcesses 1 and is, by construction, about
         * to be full of this very region's fifty boxes — queueing first light behind them
         * would make it arrive last, which is an exquisite way of achieving nothing.
         */
        $this->onQueue(QueueLane::Default->value);
    }

    public function uniqueId(): string
    {
        // Two travellers in the same neighbourhood are one first light.
        return sprintf('%s:%.3f:%.3f', $this->regionKey, $this->lat, $this->lng);
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        RegionCatalog $catalog,
        RegionIngest $ingest,
        ResolveRegion $resolve,
        ScoutRunner $scouts,
    ): void {
        $region = $catalog->named($this->regionKey);

        // Longitude degrees shrink with latitude, and this product's test region is at 63°N
        // where they are less than half the size they are at the equator. A square in
        // degrees would be a letterbox in metres.
        $lngHalf = self::HALF_SIDE_DEGREES_LAT / max(0.2, cos(deg2rad($this->lat)));

        $box = new ScoutRequest(
            regionKey: $region->key,
            south: $this->lat - self::HALF_SIDE_DEGREES_LAT,
            west: $this->lng - $lngHalf,
            north: $this->lat + self::HALF_SIDE_DEGREES_LAT,
            east: $this->lng + $lngHalf,
            locale: $region->locale,
        );

        try {
            $ingested = $ingest->ingest($region, 'osm', $box);
        } catch (Throwable $e) {
            // First light is a courtesy, not a contract. The region build behind it is the
            // thing that must succeed; if Overpass is having a bad minute, the traveller
            // waits the way they used to and nothing is broken.
            report($e);

            return;
        }

        /*
         * Resolve immediately — source rows are not places, and a place nobody resolved is
         * a place no scout can see. This is the step whose absence would make the whole job
         * look like it had worked while the screen stayed empty.
         *
         * `unresolvedTiles`, not `tilesFor`: the latter is every tile in the region holding
         * a place, which for a 584 km² region is the whole point of the *background* build
         * and would drag first light back to being slow. Straight after our box, the only
         * unresolved rows in this region are the ones we just wrote.
         */
        $tiles = $resolve->unresolvedTiles($region);
        $resolved = $resolve->resolveTiles($tiles);

        /*
         * ...and then FORGET THE TILES.
         *
         * The scouts have already cached "there is nothing in this hexagon" — that is
         * exactly what the founder's pipeline log showed in Umeå: `19 tiles (19 hit, 0
         * filled), 0 candidates`. Cached emptiness, with a one-day TTL. Without this line
         * the places land in the database and the feed stays dark until tomorrow, which is
         * the most demoralising possible outcome: it worked, and it looks like it didn't.
         */
        $scouts->forgetTiles($tiles);

        Log::info('First light.', [
            'region' => $region->key,
            'ingested' => $ingested,
            'resolved' => $resolved,
            'tiles_forgotten' => count($tiles),
        ]);
    }
}
