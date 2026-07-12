<?php

declare(strict_types=1);

namespace App\Domain\Sources\Data;

use InvalidArgumentException;

/**
 * Bounded ingest regions — scouts never crawl the world (PRD §9.2).
 * Reference data in code, like the taxonomy maps: adding a region is a
 * reviewed change, not a config flag.
 */
final readonly class IngestRegion
{
    /**
     * ~0.05° — a few kilometres a side. Small enough that one Overpass query for it
     * answers in seconds rather than minutes, which is the whole point.
     */
    private const CELL_DEGREES = 0.05;

    /** A region must not become a thousand jobs. */
    private const MAX_BOXES = 128;

    public function __construct(
        public string $key,
        public string $name,
        public float $south,
        public float $west,
        public float $north,
        public float $east,
        public string $locale,
    ) {}

    public static function named(string $key): self
    {
        $region = self::all()[$key] ?? null;

        if ($region === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown ingest region "%s". Known: %s', $key, implode(', ', array_keys(self::all())),
            ));
        }

        return $region;
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        return [
            /*
            | Stockholm — the home region (renamed from `stockholm-test`, and
            | widened from a 93 km² central slice to the whole municipality,
            | 2026-07-14).
            |
            | The old box stopped at Liljeholmen/Södermalm/Gamla stan, which was
            | right while it was a pipeline test. It is not right now: this is
            | where the app is actually used, and a feed that goes quiet the
            | moment you walk to Farsta or Kista is a feed that has failed.
            |
            | Bounds are Stockholms kommun: Skärholmen and Farsta in the south up
            | to Kista and Akalla in the north, Hässelby in the west out to
            | Djurgården in the east. ~584 km², six times the old region and three
            | times Paris.
            |
            | It deliberately stops at the municipal boundary. Solna, Sundbyberg,
            | Lidingö and Nacka are their own municipalities — real places worth
            | having, but a separate region rather than a quietly expanding box.
            */
            'stockholm' => new self(
                key: 'stockholm',
                name: 'Stockholm',
                south: 59.220,
                west: 17.760,
                north: 59.430,
                east: 18.200,
                locale: 'sv',
            ),

            /*
            | The France-trip corridor (PRD §8.0, CURATION §4) — Jul 27–Aug 7 2026.
            |
            | City-scale boxes, deliberately. A region is what a traveler can walk
            | or ride out of in a session, not an administrative boundary: bigger
            | boxes cost ingest time and Overpass patience without ever being
            | scouted, because coverage geometry never reaches them.
            |
            | locale `fr` is load-bearing now, not decorative: the adapters read
            | name:{locale}, query Wikidata for French labels, and follow the
            | fr.wikipedia sitelink (E2). Every one of those was hard-coded to
            | Swedish until this corridor forced the fix.
            */
            ...self::franceCorridor(),
        ];
    }

    /**
     * @return array<string, self>
     */
    private static function franceCorridor(): array
    {
        $cities = [
            // key            name                  south     west      north     east
            ['paris', 'Paris', 48.8150, 2.2240, 48.9020, 2.4700],
            ['nantes', 'Nantes', 47.1800, -1.6100, 47.2600, -1.4950],
            ['bordeaux', 'Bordeaux', 44.8000, -0.6400, 44.8900, -0.5300],
            ['toulouse', 'Toulouse', 43.5600, 1.3800, 43.6500, 1.4900],
            // Nice runs to the water on purpose: the coast IS the opportunity
            // (light on the sea, the Promenade), and tide/light windows arrive
            // with E16 — a box cut inland would have nothing to hang them on.
            ['nice', 'Nice', 43.6550, 7.1950, 43.7350, 7.3200],
            ['lyon', 'Lyon', 45.7200, 4.7800, 45.8000, 4.9000],
            ['dijon', 'Dijon', 47.2950, 5.0000, 47.3500, 5.0800],
        ];

        $regions = [];
        foreach ($cities as [$key, $name, $south, $west, $north, $east]) {
            $regions[$key] = new self(
                key: $key,
                name: $name,
                south: $south,
                west: $west,
                north: $north,
                east: $east,
                locale: 'fr',
            );
        }

        return $regions;
    }

    public function toScoutRequest(): ScoutRequest
    {
        return new ScoutRequest(
            regionKey: $this->key,
            south: $this->south,
            west: $this->west,
            north: $this->north,
            east: $this->east,
            locale: $this->locale,
        );
    }

    /**
     * The region, cut into a grid of small boxes — one job's worth of work each.
     *
     * This exists because of a hard ceiling that is easy to miss: a job's `timeout`
     * is capped by the queue's `retry_after`, which is itself capped by how long you
     * are willing to leave a genuinely dead job undetected. So there is a maximum
     * duration ANY job may have, and "make the timeout bigger" is a treadmill that
     * ends at that wall.
     *
     * Stockholm hit it. One job fetching 584 km² of Overpass held its reservation
     * past retry_after, the queue decided it was dead, handed it to a second worker,
     * and `tries = 1` killed it — MaxAttemptsExceeded, having done nothing wrong.
     * Worse, an hour of successfully fetched elements died with it, unwritten,
     * because the old ingest buffered the whole region in memory and persisted only
     * at the end.
     *
     * A box is therefore the unit of WORK and the unit of PERSISTENCE: one Overpass
     * query, one upsert, a couple of minutes. A box that dies costs a box.
     *
     * @return list<ScoutRequest>
     */
    public function boxes(float $cellDegrees = self::CELL_DEGREES): array
    {
        $latSteps = max(1, (int) ceil(($this->north - $this->south) / $cellDegrees));
        $lngSteps = max(1, (int) ceil(($this->east - $this->west) / $cellDegrees));

        // A pathological region must not turn into a thousand jobs. Grow the cells
        // instead — a slightly bigger box is a slower query; a thousand boxes is a
        // day of politeness sleeps.
        if ($latSteps * $lngSteps > self::MAX_BOXES) {
            return $this->boxes($cellDegrees * 2);
        }

        $latSize = ($this->north - $this->south) / $latSteps;
        $lngSize = ($this->east - $this->west) / $lngSteps;

        $boxes = [];

        for ($i = 0; $i < $latSteps; $i++) {
            for ($j = 0; $j < $lngSteps; $j++) {
                $boxes[] = new ScoutRequest(
                    regionKey: $this->key,
                    south: $this->south + $i * $latSize,
                    west: $this->west + $j * $lngSize,
                    north: $this->south + ($i + 1) * $latSize,
                    east: $this->west + ($j + 1) * $lngSize,
                    locale: $this->locale,
                );
            }
        }

        return $boxes;
    }
}
