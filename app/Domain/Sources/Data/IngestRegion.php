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
}
