<?php

declare(strict_types=1);

namespace App\Domain\Sources\Actions;

use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\DerivedRegion;
use App\Domain\Sources\Services\RegionCatalog;
use App\Domain\Sources\Services\ReverseGeocoder;
use Illuminate\Support\Str;

/**
 * Mint a region around a position we have never heard of (E48).
 *
 * ## Why a box and not a municipality
 *
 * The obvious move is to reverse-geocode the administrative boundary and ingest that.
 * It is wrong, and the codebase already says why — `IngestRegion`, on the France
 * corridor: *"A region is what a traveler can walk or ride out of in a session, not an
 * administrative boundary: bigger boxes cost ingest time and Overpass patience without
 * ever being scouted, because coverage geometry never reaches them."*
 *
 * Skellefteå *kommun* is ~7,000 km². The Stockholm region is 584 km² and takes ~45
 * Overpass boxes. Ingesting the municipality would mean hundreds of boxes, hours of
 * politeness sleeps, and a very thorough map of forest that no session's coverage cone
 * will ever touch.
 *
 * So a derived region is sized to what a person could actually *get to* — capped, and
 * generous enough that walking a few streets does not immediately need another one.
 */
final class DeriveRegionForPosition
{
    /** Big enough to explore out of; small enough to ingest before the user gives up. */
    private const HALF_SPAN_KM = 12.0;

    /** A degree of latitude is ~111 km everywhere. Longitude shrinks with the cosine. */
    private const KM_PER_DEGREE_LAT = 111.0;

    public function __construct(
        private readonly RegionCatalog $catalog,
        private readonly ReverseGeocoder $geocoder,
        private readonly TileIndexer $tiles,
    ) {}

    /**
     * The region covering this point — the existing one if there is one, a new one if not.
     *
     * Dedupe first, always. Someone exploring the next street over from a region we
     * already have must not mint a second, overlapping one — that is how you end up
     * ingesting the same Overpass boxes twice and calling it two cities.
     */
    public function __invoke(float $lat, float $lng, ?int $userId = null): IngestRegion
    {
        $existing = $this->catalog->covering($lat, $lng);

        if ($existing !== null) {
            return $existing;
        }

        /*
         * Name it from the TILE, never from the person (PRD §16; ROPA §6).
         *
         * Nominatim is a third party, and the only thing it needs in order to say "this is
         * Skellefteå" is roughly where Skellefteå is. Handing it a traveller's exact
         * position to answer that would put a real coordinate in someone else's logs for
         * no gain at all — the same mistake ROPA's open finding B3 records against
         * Open-Meteo. A res-8 cell is ~0.74 km²; the city is the same, the doorstep is not.
         */
        $described = $this->geocoder->forTile($this->tiles->cellFor($lat, $lng));

        $latSpan = self::HALF_SPAN_KM / self::KM_PER_DEGREE_LAT;

        // Longitude degrees get narrower toward the poles, and the launch region is at
        // 59°N where a degree of longitude is barely half what it is at the equator. A
        // box that ignored this would be twice as wide as intended in Stockholm — and in
        // Skellefteå, at 64°N, worse.
        $lngSpan = $latSpan / max(0.1, cos(deg2rad($lat)));

        $region = DerivedRegion::query()->create([
            'key' => $this->key($described['name'], $lat, $lng),
            'name' => $described['name'],
            'south' => round($lat - $latSpan, 6),
            'west' => round($lng - $lngSpan, 6),
            'north' => round($lat + $latSpan, 6),
            'east' => round($lng + $lngSpan, 6),
            'locale' => $described['locale'],
            'requested_by_user_id' => $userId,
            'requested_at' => now(),
        ]);

        return $region->toIngestRegion();
    }

    /**
     * A stable, readable key — `skelleftea-6475-2095`.
     *
     * The coordinates are in it on purpose: two different places share a name more often
     * than you would like (there are several Springfields), and a key collision here
     * would silently merge two regions into one bounding box spanning both.
     */
    private function key(string $name, float $lat, float $lng): string
    {
        return sprintf(
            '%s-%d-%d',
            Str::slug(Str::ascii($name)) ?: 'region',
            round($lat * 100),
            round($lng * 100),
        );
    }
}
