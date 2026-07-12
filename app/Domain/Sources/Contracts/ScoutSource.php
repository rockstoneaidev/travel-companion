<?php

declare(strict_types=1);

namespace App\Domain\Sources\Contracts;

use App\Domain\Sources\Data\ScoutRequest;
use DateInterval;

/**
 * How external data enters this system — fixed by PRD §9.1 (conventions/09).
 * Every source implements it, without exception; that is what makes sources
 * pluggable without touching the recommendation engine.
 */
interface ScoutSource
{
    /** Cheap, pure, no I/O: "do I have anything to say about this request?" */
    public function supports(ScoutRequest $request): bool;

    /**
     * The only method that talks to the outside world. Returns raw source payloads.
     *
     * @return list<array<string, mixed>>
     */
    public function search(ScoutRequest $request): array;

    /**
     * Raw → the shared candidate format. Pure and testable: given a fixture of
     * raw source JSON it returns candidates, so every adapter's normalization
     * is unit-tested against recorded fixtures.
     *
     * $locale is the region's language (ScoutRequest::$locale, from IngestRegion).
     * It is passed explicitly rather than read from the request so normalize()
     * stays pure — no region is hard-coded into any adapter (PRD §9.4).
     *
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    public function normalize(array $raw, string $locale): array;

    /** Per PRD §9.3's TTL-by-data-class table. */
    public function ttl(): DateInterval;
}
