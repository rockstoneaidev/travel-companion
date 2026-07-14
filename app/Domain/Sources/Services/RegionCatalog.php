<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\DerivedRegion;

/**
 * Every region we know: the ones we CHOSE, and the ones we were ASKED to learn (E48).
 *
 * `IngestRegion::all()` remains the hand-reviewed catalogue in code — Stockholm and the
 * France corridor are product decisions and belong in a pull request. This is that
 * catalogue plus the regions the world model derived because a real user walked into an
 * area we had never heard of.
 *
 * Everything downstream — the build job, the pack selector, trip naming, the admin
 * World-model page — asks HERE, so a learned region is a first-class region everywhere,
 * with no branch anywhere saying "unless it was derived".
 */
final class RegionCatalog
{
    /** @return array<string, IngestRegion> */
    public function all(): array
    {
        $derived = [];

        foreach (DerivedRegion::query()->orderBy('requested_at')->get() as $region) {
            $derived[$region->key] = $region->toIngestRegion();
        }

        // Code first: a hand-reviewed region always wins a key collision, because it is
        // the one somebody read.
        return [...$derived, ...IngestRegion::all()];
    }

    public function find(string $key): ?IngestRegion
    {
        return $this->all()[$key] ?? null;
    }

    public function named(string $key): IngestRegion
    {
        $region = $this->find($key);

        if ($region === null) {
            throw new \InvalidArgumentException("Unknown region [{$key}].");
        }

        return $region;
    }

    /**
     * The region covering this point, if any — the dedupe question.
     *
     * Asked before deriving anything: someone exploring the next street over from a
     * region we already have must not mint a second, overlapping one.
     */
    public function covering(float $lat, float $lng): ?IngestRegion
    {
        foreach ($this->all() as $region) {
            if ($lat >= $region->south && $lat <= $region->north
                && $lng >= $region->west && $lng <= $region->east) {
                return $region;
            }
        }

        return null;
    }
}
