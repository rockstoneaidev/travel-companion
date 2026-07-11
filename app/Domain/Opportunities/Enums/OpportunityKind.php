<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The temporal nature of an opportunity (PRD §14.2) — governs time_window
 * semantics, expires_at, and which scoring signals dominate. Distinct from
 * PlaceType: PlaceType is what a place IS, OpportunityKind is the temporal
 * nature of a MOMENT (TAXONOMY.md §6).
 */
enum OpportunityKind: string
{
    use HasOptions;

    /** Stable place, no inherent time pressure. Low urgency by construction. */
    case Evergreen = 'evergreen';

    /** A fleeting NOW window — closing soon, short queue, ideal light/weather. */
    case Ephemeral = 'ephemeral';

    /** A scheduled happening with fixed starts_at/ends_at. Expires at ends_at. */
    case Event = 'event';

    /** Available only within a season/date range. Mild urgency near the range's end. */
    case Seasonal = 'seasonal';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
