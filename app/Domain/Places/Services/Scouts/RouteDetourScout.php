<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/**
 * The reason to pull over (E35; PRD §9.1 `RouteDetourScout`).
 *
 * ## Why this is not just NearbyPlaceScout with a bigger radius
 *
 * `nearby` is Near-range and returns everything, because within a 1.2 km ring
 * everything is plausible — a bakery is a fine answer to "what's around me".
 *
 * A corridor is a different question. At 90 km/h, "around me" is a place you have
 * already passed, and the only candidates that mean anything are the ones worth
 * **stopping the car for**. That is a real, and much smaller, set: a lake you can
 * swim in, a church that has been there for 800 years, a viewpoint. Nobody leaves a
 * motorway for a corner shop, and a feed that offers them one has misunderstood
 * what it is looking at.
 *
 * So the filter is not distance — the coverage geometry already did distance. The
 * filter is **domain**, and the domains below are the ones that survive a detour.
 *
 * Note what is excluded, and that it is deliberate:
 *
 *   - `food_drink` — near-range only, via `nearby`. A café is a reason to stop when
 *     you are already there and hungry; it is not a reason to leave a road. (Lunch
 *     on a drive is a *practical* need, and E39's PracticalScout owns it.)
 *   - `shops_craft` — same, minus the hunger.
 *   - `practical` — E39's, and near-range by definition: a toilet 30 km ahead is not
 *     an opportunity, it is a countdown.
 *   - `events` — carried by the event scouts, which have their own time semantics.
 *
 * The scoring model already knows what to do with what comes back: `route_fit`
 * (SubScores::routeFit) prices the detour in minutes, and the `route` context weight
 * vector leans on it. This scout's whole job is to make sure the *right things* are
 * in the candidate set for it to price.
 */
final class RouteDetourScout extends DbScout
{
    /**
     * Worth leaving the road for. Ordered as a claim, not alphabetically: this is the
     * product's opinion about what makes somebody take an exit they had not planned to.
     */
    private const WORTH_A_DETOUR = [
        'nature_landscape',
        'historic_heritage',
        'museum_gallery',
        'arts_culture',
        'architecture_urban',
        'religious_sacred',
        'activity_recreation',
    ];

    public function key(): string
    {
        return 'route_detour';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->placesWhere($h3Index, self::WORTH_A_DETOUR);
    }
}
