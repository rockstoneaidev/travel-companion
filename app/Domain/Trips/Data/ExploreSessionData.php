<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

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
            heading: $this->heading,
            destinationPoint: $this->destinationPoint,
            status: $this->status,
            startedAt: $this->startedAt,
            expiresAt: $this->expiresAt,
            endedAt: $this->endedAt,
        );
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
