<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Source credibility tiers (DATA-SOURCES.md §1.2). Numeric values feed the
 * confidence sub-score (SCORING.md §4.6). Tier-D can boost, never establish:
 * a D-only candidate is a lead, not a servable item (SCORING.md §2.1).
 */
enum CredibilityTier: string
{
    use HasOptions;

    case Official = 'official';    // Tier A: gov registries, tourism boards, met offices — and reviewed curated items
    case Reference = 'reference';  // Tier B: Wikidata/Wikipedia/Wikivoyage, established event APIs
    case Open = 'open';            // Tier C: OSM, municipal calendars, local press
    case Community = 'community';  // Tier D: blogs, Reddit, YouTube — hypothesis generators

    /** Credibility value for the confidence formula (SCORING.md §4.6). */
    public function credibilityValue(): float
    {
        return match ($this) {
            self::Official => 0.95,
            self::Reference => 0.85,
            self::Open => 0.70,
            self::Community => 0.40,
        };
    }

    /** Tier-D alone can never establish that an opportunity exists (SCORING.md §2.1). */
    public function canEstablishExistence(): bool
    {
        return $this !== self::Community;
    }
}
