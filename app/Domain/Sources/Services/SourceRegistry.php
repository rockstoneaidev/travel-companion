<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Adapters\DatatourismeAdapter;
use App\Domain\Sources\Adapters\MerimeeAdapter;
use App\Domain\Sources\Adapters\OsmAdapter;
use App\Domain\Sources\Adapters\OvertureAdapter;
use App\Domain\Sources\Adapters\WikidataAdapter;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\RateLimit;
use App\Domain\Sources\Data\SourceDescriptor;
use App\Domain\Sources\Enums\ScoutRange;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;
use DateInterval;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The runtime catalog of sources (conventions/09): every adapter registers
 * here, and the descriptor metadata is what the persistence layer and scout
 * runner consult at runtime to decide what they are allowed to do.
 *
 * Rate limits and circuit breakers belong to the registry, not to adapters;
 * the E5 scout runner asks permission through this class.
 */
final class SourceRegistry
{
    /** @var array<string, class-string<ScoutSource>> */
    private const ADAPTERS = [
        OvertureAdapter::KEY => OvertureAdapter::class,
        OsmAdapter::KEY => OsmAdapter::class,
        WikidataAdapter::KEY => WikidataAdapter::class,
        MerimeeAdapter::KEY => MerimeeAdapter::class,
        DatatourismeAdapter::KEY => DatatourismeAdapter::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function descriptor(string $key): SourceDescriptor
    {
        return $this->descriptors()[$key] ?? throw $this->unknown($key);
    }

    public function adapter(string $key): ScoutSource
    {
        $class = self::ADAPTERS[$key] ?? throw $this->unknown($key);

        return $this->container->make($class);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::ADAPTERS);
    }

    /** Attribution strings for the in-app attribution screen (ODBL-REVIEW §6 rule 6). */
    public function attributions(): array
    {
        return array_map(fn (SourceDescriptor $d): string => $d->attribution, array_values($this->descriptors()));
    }

    /** @return array<string, SourceDescriptor> */
    public function descriptors(): array
    {
        // Static places TTL: weeks (PRD §9.3 data-class table). All three are
        // the ODbL-compatible open core — Persistable is what lets entity
        // resolution merge them into places_core (ODBL-REVIEW §6).
        return [
            OvertureAdapter::KEY => new SourceDescriptor(
                key: OvertureAdapter::KEY,
                license: SourceLicense::CdlaPermissive,
                storage: StoragePolicy::Persistable,
                attribution: '© Overture Maps Foundation, CDLA-Permissive 2.0',
                ttl: new DateInterval('P30D'),
                adapterVersion: OvertureAdapter::VERSION,
                rateLimit: new RateLimit(perMinute: 60),
                credibilityTier: CredibilityTier::Open,
                scoutRange: ScoutRange::Near,
            ),
            OsmAdapter::KEY => new SourceDescriptor(
                key: OsmAdapter::KEY,
                license: SourceLicense::Odbl,
                storage: StoragePolicy::Persistable,
                attribution: '© OpenStreetMap contributors, ODbL',
                ttl: new DateInterval('P30D'),
                adapterVersion: OsmAdapter::VERSION,
                rateLimit: new RateLimit(perMinute: 30),
                credibilityTier: CredibilityTier::Open,
                scoutRange: ScoutRange::Near,
            ),
            WikidataAdapter::KEY => new SourceDescriptor(
                key: WikidataAdapter::KEY,
                license: SourceLicense::Cc0,
                storage: StoragePolicy::Persistable,
                attribution: 'Wikidata, CC0 1.0',
                ttl: new DateInterval('P30D'),
                adapterVersion: WikidataAdapter::VERSION,
                rateLimit: new RateLimit(perMinute: 30),
                credibilityTier: CredibilityTier::Reference,
                scoutRange: ScoutRange::Full, // heritage/history is worth the drive (conventions/09)
            ),

            // France corridor (E13, DATA-SOURCES §7). Mérimée is a national
            // ministry registry — Tier A, the strongest open evidence of
            // existence we have, and it can establish a place on its own.
            MerimeeAdapter::KEY => new SourceDescriptor(
                key: MerimeeAdapter::KEY,
                license: SourceLicense::LicenceOuverte,
                storage: StoragePolicy::Persistable,
                attribution: 'Base Mérimée, Ministère de la Culture — Licence Ouverte (Etalab)',
                ttl: new DateInterval('P90D'),
                adapterVersion: MerimeeAdapter::VERSION,
                rateLimit: new RateLimit(perMinute: 60),
                credibilityTier: CredibilityTier::Official,
                scoutRange: ScoutRange::Full, // a protected monument is worth the detour
            ),
            DatatourismeAdapter::KEY => new SourceDescriptor(
                key: DatatourismeAdapter::KEY,
                license: SourceLicense::LicenceOuverte,
                storage: StoragePolicy::Persistable,
                attribution: 'DATAtourisme — Licence Ouverte (Etalab)',
                ttl: new DateInterval('P30D'),
                adapterVersion: DatatourismeAdapter::VERSION,
                rateLimit: new RateLimit(perMinute: 60),
                credibilityTier: CredibilityTier::Official, // a tourism board on its own territory
                scoutRange: ScoutRange::Near,
            ),
        ];
    }

    private function unknown(string $key): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Unknown source "%s". Known: %s', $key, implode(', ', array_keys(self::ADAPTERS)),
        ));
    }
}
