<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use Carbon\CarbonImmutable;

/**
 * What the sun is doing for one candidate, right now (E16).
 *
 * Two different jobs, deliberately separate:
 *
 *   $closesAt  — a real `last_feasible_start` for a place that needs daylight.
 *               Without it a viewpoint is evergreen and scores no urgency at all,
 *               which is how you end up recommending a black bay at 23:00.
 *
 *   $goldenMinutesLeft — the special-moment floor (SCORING §4.3). Not a deadline:
 *               a reason. The light is good NOW and it won't be later.
 */
final readonly class LightContext
{
    public function __construct(
        public ?CarbonImmutable $closesAt = null,
        public ?int $minutesOfLightLeft = null,
        public ?int $goldenMinutesLeft = null,
    ) {}

    public function goldenHourOpen(): bool
    {
        return $this->goldenMinutesLeft !== null;
    }

    /** The line a GO NOW card can truthfully say — or null, if there is nothing true to say. */
    public function note(): ?string
    {
        if ($this->goldenMinutesLeft !== null) {
            return "the light is good for about another {$this->goldenMinutesLeft} min";
        }

        if ($this->minutesOfLightLeft !== null && $this->minutesOfLightLeft <= 90) {
            return "~{$this->minutesOfLightLeft} min of light left";
        }

        return null;
    }

    /** @return array<string, mixed> The decision trace (PRD §15). */
    public function toTrace(): array
    {
        return [
            'closes_at' => $this->closesAt?->toIso8601String(),
            'light_left_min' => $this->minutesOfLightLeft,
            'golden_left_min' => $this->goldenMinutesLeft,
        ];
    }
}
