<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The pipeline state machine (PRD §10) — what makes the system debuggable.
 * RAW_CANDIDATE → NORMALIZED → ENRICHED → SCORED → { SERVED | DIGEST | WATCHING | DISCARDED }
 */
enum OpportunityStatus: string
{
    use HasOptions;

    case RawCandidate = 'raw_candidate';
    case Normalized = 'normalized';
    case Enriched = 'enriched';
    case Scored = 'scored';
    case Served = 'served';
    case Digest = 'digest';
    case Watching = 'watching';
    case Discarded = 'discarded';
    case Expired = 'expired';

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Discarded, self::Expired];
    }
}
