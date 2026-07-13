<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Data;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Places\Data\PlaceData;
use Carbon\CarbonImmutable;

/**
 * One item of a session's feed, as the delivery layer sees it.
 *
 * There is deliberately **no score on this DTO.** Scoring and feed selection are
 * E7 (SCORING.md); until then the feed is ordered by distance and says so. When
 * E7 lands it adds the sub-scores and the `scoring_model_version` here, and
 * `ListOpportunitiesForSession` stops being an ordering and starts being a
 * ranking.
 */
final readonly class SessionOpportunityData
{
    public function __construct(
        public string $id,
        public OpportunityKind $kind,
        public OpportunityStatus $status,
        public ?string $title,
        public ?string $summary,
        public PlaceData $place,
        public ?int $distanceMeters,
        public ?CarbonImmutable $windowStartsAt,
        public ?CarbonImmutable $windowEndsAt,
        public CarbonImmutable $expiresAt,
        public ?string $recommendationId = null,   // E7: the trace row feedback posts against
        public ?float $walkMinutes = null,         // Stage-A final approach (reachability trace)
        public bool $urgent = false,               // the GO NOW slot — at most one per feed (SCREENS S1)
        /**
         * The photo, with its attribution (DESIGN §3 — a card has an image slot and a
         * paper-stripe fallback). 1,516 images were being fetched by the photos phase
         * and shown on exactly one screen; the feed, which is the screen people
         * actually look at, rendered none of them.
         */
        public ?array $image = null,
        /**
         * Already kept (SCREENS S6's `saved`/`unsaved` toggle, latest-wins).
         *
         * The card has to be able to say "Kept" on a cold load, not just in the seconds
         * after the tap. Local React state alone would show "Keep" on an item the KEPT
         * screen is simultaneously listing — the same reload amnesia that let dismissals
         * come back, wearing a different hat.
         */
        public bool $kept = false,
    ) {}

    /**
     * The one item that wins the GO NOW slot (Opportunities\Services\UrgentSlot).
     *
     * ===================================================================
     *  EVERY FIELD MUST BE CARRIED. `kept` was not, and it cost the user their Keep.
     * ===================================================================
     *
     * This is a hand-rolled copy constructor, and it did what hand-rolled copy
     * constructors do: it silently dropped the field somebody added after it was
     * written. `kept` fell back to its default of FALSE, so the moment an item was
     * promoted to GO NOW it forgot it had been kept — the card said "Keep" again on the
     * very next reload, and the user's tap had gone nowhere they could see.
     *
     * It only broke in the EVENING, which is why it survived: whether an item wins the
     * urgent slot depends on its time window against the clock, so the bug was invisible
     * every time anyone happened to look at it before five.
     */
    public function asUrgent(): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            status: $this->status,
            title: $this->title,
            summary: $this->summary,
            place: $this->place,
            distanceMeters: $this->distanceMeters,
            windowStartsAt: $this->windowStartsAt,
            windowEndsAt: $this->windowEndsAt,
            expiresAt: $this->expiresAt,
            recommendationId: $this->recommendationId,
            walkMinutes: $this->walkMinutes,
            urgent: true,
            image: $this->image,
            kept: $this->kept,
        );
    }
}
