<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The taste axis: why a place is worth changing behaviour for (TAXONOMY.md §4).
 * Cross-module by nature — Places tags with it, Profiles weights it,
 * Recommendations scores on it, Curation assigns it.
 *
 * personal_fit is learned on these ~14 facets, never on the ~77 place types
 * (TAXONOMY.md §5) — that is the load-bearing design choice.
 */
enum AppealFacet: string
{
    use HasOptions;

    case History = 'history';
    case Architecture = 'architecture';
    case Nature = 'nature';
    case Scenic = 'scenic';
    case FoodDrink = 'food_drink';
    case Art = 'art';
    case Craft = 'craft';
    case Spiritual = 'spiritual';
    case LocalLife = 'local_life';
    case Family = 'family';
    case Active = 'active';
    case Offbeat = 'offbeat';
    case Romantic = 'romantic';
    case Educational = 'educational';

    public function label(): string
    {
        return match ($this) {
            self::FoodDrink => 'Food & drink',
            self::LocalLife => 'Local life',
            default => ucfirst($this->value),
        };
    }
}
