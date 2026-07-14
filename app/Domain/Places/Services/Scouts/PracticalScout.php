<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/**
 * The things you need, not the things you'd love (E39; PRD §9.1 PracticalScout).
 *
 * ## Why this is a scout of its own, and not just part of `nearby`
 *
 * The rest of the pipeline answers "what is worth your time here?" A toilet is never the
 * answer to that question — nobody's trip is improved by being told about a public
 * convenience. But a toilet is very much the answer to a *different* question the traveller
 * is sometimes urgently asking, and a companion that cannot answer it when it matters is
 * missing the "companion" part.
 *
 * So practical infrastructure is scored and served differently by intent: pharmacies,
 * toilets, EV charging, shelter, transport hubs. They surface when they are *relevant* —
 * near, and (for the notification path) when the moment calls for it — and they stay quiet
 * otherwise. They are not opportunities; they are utilities.
 *
 * ## Near range, and it is not the same "near" as `nearby`
 *
 * `nearby` is near because a café 30 km ahead is noise. Practical is near for a stronger
 * reason: a pharmacy 30 km ahead is not noise, it is a COUNTDOWN — knowing the nearest one
 * is 40 minutes away when you need it now is worse than useless, it is stressful. The
 * payoff gradient (conventions/12) is at its steepest here: practical value collapses with
 * distance faster than anything else in the product.
 *
 * The places themselves are already in the world model — OSM tags pharmacy, toilets,
 * charging_station, shelter and the transport hubs (E39 added the station tags). This scout
 * is the lens that reads only them.
 */
final class PracticalScout extends DbScout
{
    public function key(): string
    {
        return 'practical';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Near;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->placesWhere($h3Index, ['practical']);
    }
}
