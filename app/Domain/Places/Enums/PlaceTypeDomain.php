<?php

declare(strict_types=1);

namespace App\Domain\Places\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Top level of the Type axis (TAXONOMY.md §2). Domain-level granularity is
 * what repetition_penalty reads ("too many religious_sacred today").
 */
enum PlaceTypeDomain: string
{
    use HasOptions;

    case ReligiousSacred = 'religious_sacred';
    case HistoricHeritage = 'historic_heritage';
    case MuseumGallery = 'museum_gallery';
    case NatureLandscape = 'nature_landscape';
    case FoodDrink = 'food_drink';
    case ArtsCulture = 'arts_culture';
    case ArchitectureUrban = 'architecture_urban';
    case ShopsCraft = 'shops_craft';
    case ActivityRecreation = 'activity_recreation';
    case Events = 'events';
    case Practical = 'practical'; // Phase 2 (PRD §9.1 PracticalScout)

    public function label(): string
    {
        return ucwords(str_replace('_', ' & ', match ($this) {
            self::ReligiousSacred => 'religious_sacred',
            self::HistoricHeritage => 'historic_heritage',
            self::MuseumGallery => 'museum_gallery',
            self::NatureLandscape => 'nature_landscape',
            self::FoodDrink => 'food_drink',
            self::ArtsCulture => 'arts_culture',
            self::ArchitectureUrban => 'architecture_urban',
            self::ShopsCraft => 'shops_craft',
            self::ActivityRecreation => 'activity_recreation',
            self::Events => 'events',
            self::Practical => 'practical',
        }));
    }
}
