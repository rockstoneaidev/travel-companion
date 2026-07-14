<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Data;

/**
 * What was erased. GDPR Article 5 accountability (PRD §16): a deletion the user
 * asked for should be able to say what it did.
 */
final readonly class LocationErasureReport
{
    public function __construct(
        public string $tripId,
        public int $tripRowsErased,
        public int $contextEventsErased,
        // Recommendation traces whose serve anchor was erased (E46). Traces survive;
        // their geography does not.
        public int $tracesErased = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trip_id' => $this->tripId,
            'trip_rows_erased' => $this->tripRowsErased,
            'context_events_erased' => $this->contextEventsErased,
            'traces_erased' => $this->tracesErased,
        ];
    }
}
