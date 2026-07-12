<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Data;

/**
 * What the retention pass actually did (PRD §16).
 *
 * Counted and logged, not assumed. "The retention job runs nightly" is a claim;
 * "it coarsened 1,412 context events last night" is a fact, and accountability
 * (GDPR Art. 5) is the difference between the two.
 */
final readonly class RetentionReport
{
    public function __construct(
        public int $contextEvents,
        public int $sessions,
        public int $trips,
        public int $traces,
    ) {}

    public function total(): int
    {
        return $this->contextEvents + $this->sessions + $this->trips + $this->traces;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'context_events' => $this->contextEvents,
            'sessions' => $this->sessions,
            'trips' => $this->trips,
            'traces' => $this->traces,
        ];
    }
}
