<?php

declare(strict_types=1);

namespace App\Domain\Agent\Data;

/**
 * The situational facts a generation may use — "facts, not vibes"
 * (conventions/10).
 *
 * Everything here is measured by the application: the clock, the session's own
 * settings, the reachability trace. The model is told these; it never computes
 * them, and it is never asked to guess one. Weather and light windows join this
 * struct with E16.
 */
final readonly class ContextData
{
    public function __construct(
        public string $partOfDay,        // morning · afternoon · evening — from the clock
        public string $travelMode,       // walk · bike · drive
        public ?int $walkMinutes,        // measured by the Stage-A estimator, never generated
        public ?string $cityName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'part_of_day' => $this->partOfDay,
            'travel_mode' => $this->travelMode,
            'walk_minutes' => $this->walkMinutes,
            'city' => $this->cityName,
        ], static fn ($v): bool => $v !== null);
    }

    public function toPrompt(): string
    {
        $lines = ["- part of day: {$this->partOfDay}", "- how they are travelling: {$this->travelMode}"];

        if ($this->walkMinutes !== null) {
            $lines[] = "- minutes away: {$this->walkMinutes}";
        }

        return implode("\n", $lines);
    }
}
