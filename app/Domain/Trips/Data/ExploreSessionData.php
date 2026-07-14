<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;

/**
 * The session as other modules see it (conventions/01: another module may hold
 * a session id and this DTO, never the ExploreSession model).
 */
final readonly class ExploreSessionData
{
    public function __construct(
        public string $id,
        public string $tripId,
        public int $userId,
        public ?Coordinates $origin,
        public int $timeBudgetMinutes,
        public TravelMode $travelMode,
        public ?int $heading,
        public ?Coordinates $destinationPoint,
        public ExploreSessionStatus $status,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $endedAt,
        public ContextSource $contextSource = ContextSource::Device,
    ) {}

    public static function fromModel(ExploreSession $session): self
    {
        return new self(
            id: $session->id,
            tripId: $session->trip_id,
            userId: $session->user_id,
            origin: $session->origin,
            timeBudgetMinutes: $session->time_budget_minutes,
            travelMode: $session->travel_mode,
            heading: $session->heading,
            destinationPoint: $session->destination_point,
            status: $session->status,
            startedAt: $session->started_at,
            expiresAt: $session->expires_at,
            endedAt: $session->ended_at,
            contextSource: $session->context_source,
        );
    }

    /**
     * The same session, ranked from somewhere else (E46).
     *
     * The living feed re-anchors when the user moves, and everything downstream of
     * the anchor — the coverage geometry, the reachability gate, the friction
     * sub-score — reads `origin`. So a re-anchor is exactly "this session, but
     * origin is where they are now", and the rest of the pipeline needs no notion
     * of movement at all.
     *
     * The SESSION's origin is not touched by this: `explore_sessions.origin` means
     * "where this session started" and is immutable. The rank origin lives on the
     * serve batch (`recommendations.anchor`).
     *
     * ## Continuous re-aiming (E35)
     *
     * A re-anchor carries a fact nothing else in the system has: **two positions and
     * the order they happened in.** That is a heading — measured, not declared.
     *
     * So the cone turns with the traveller. Without this, a session that started
     * pointing north keeps searching north for its whole life, and a driver who takes
     * the westbound exit gets a feed aimed at the road they didn't take: the coverage
     * geometry would be scouting behind them, and the reachability gate would be
     * pricing detours to places they are driving away from.
     *
     * Two restraints, both deliberate:
     *
     *   - **A declared destination wins.** If the session has a `destination_point`,
     *     coverage is a corridor and the heading is unused — inferring one would be
     *     noise, and worse, it would flap on every bend in the road while the corridor
     *     stays correctly aimed at where the person actually said they are going.
     *   - **Only from a real move.** The caller re-anchors on `min_drift_meters` (400 m)
     *     of drift, so the bearing below is computed over a leg long enough to mean
     *     something. A bearing derived from two GPS fixes ten metres apart is a
     *     measurement of GPS jitter, not of intent.
     */
    public function reAnchoredAt(Coordinates $origin): self
    {
        return new self(
            id: $this->id,
            tripId: $this->tripId,
            userId: $this->userId,
            origin: $origin,
            timeBudgetMinutes: $this->timeBudgetMinutes,
            travelMode: $this->travelMode,
            heading: $this->reAimed($origin),
            destinationPoint: $this->destinationPoint,
            status: $this->status,
            startedAt: $this->startedAt,
            expiresAt: $this->expiresAt,
            endedAt: $this->endedAt,
            contextSource: $this->contextSource,
        );
    }

    /**
     * The bearing from where they were to where they are now, in degrees clockwise
     * from true north — the direction they are actually travelling (E35).
     *
     * Standard great-circle initial bearing. At the distances a re-anchor covers a
     * plane bearing would be indistinguishable, but the formula is three lines either
     * way and this one does not quietly rot at high latitude — which, for a product
     * whose test region is Stockholm and whose stretch goal is the aurora, is not a
     * hypothetical.
     */
    private function reAimed(Coordinates $to): ?int
    {
        // A declared destination already aims the search. Don't second-guess it with a
        // bearing that swings on every bend.
        if ($this->destinationPoint !== null) {
            return $this->heading;
        }

        if ($this->origin === null) {
            return $this->heading;
        }

        $fromLat = deg2rad($this->origin->lat);
        $toLat = deg2rad($to->lat);
        $deltaLng = deg2rad($to->lng - $this->origin->lng);

        $y = sin($deltaLng) * cos($toLat);
        $x = cos($fromLat) * sin($toLat) - sin($fromLat) * cos($toLat) * cos($deltaLng);

        if ($y === 0.0 && $x === 0.0) {
            return $this->heading;   // they re-anchored onto the same point; keep the old aim
        }

        return (int) round(fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0));
    }

    /**
     * How far from the origin it is plausible to reach and come back within the
     * time budget, as a straight-line radius in meters.
     *
     * This is the Stage-A travel-time estimator (PRD §10) run backwards:
     * half the budget outbound, at the mode's effective speed, divided by the
     * mode's straight-line→network path factor.
     *
     * It is a *radius*, deliberately not a coverage shape. The definitive
     * coverage — the res-8 k-ring, the heading cone, the destination corridor
     * (conventions/12) — is E5's, and needs an H3 binding this runtime does not
     * have yet. `heading` and `destination_point` are already captured for it.
     */
    public function reachMeters(): int
    {
        $outboundHours = ($this->timeBudgetMinutes / 60) / 2;

        $meters = (int) round(
            ($this->travelMode->effectiveSpeedKmh() * $outboundHours * 1000) / $this->travelMode->pathFactor()
        );

        return min($meters, (int) config('trips.session.max_reach_meters'));
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }
}
