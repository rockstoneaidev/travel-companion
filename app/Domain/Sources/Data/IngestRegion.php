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
            // Central Stockholm covering the CURATION §4 test loops: Liljeholmen
            // base, Södermalm, Gamla stan, Djurgården, Vinterviken/Gröndal.
            'stockholm-test' => new self(
                key: 'stockholm-test',
                name: 'Stockholm test region',
                south: 59.290,
                west: 17.950,
                north: 59.360,
                east: 18.160,
                locale: 'sv',
            ),
        ];
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
