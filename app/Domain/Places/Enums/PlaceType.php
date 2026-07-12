<?php

declare(strict_types=1);

namespace App\Domain\Places\Enums;

use App\Enums\AppealFacet;
use App\Enums\Concerns\HasOptions;

/**
 * Leaf level of the Type axis (TAXONOMY.md §2.1) — the noun: what a place is.
 * Exactly one per canonical place. v1 leaves; refine against real
 * Overture/OSM coverage per region under taxonomy_version (TAXONOMY.md §8).
 *
 * - domain()             → the PlaceTypeDomain (repetition_penalty granularity)
 * - baseFacets()         → rule-based facet priors, a floor the LLM pass extends (TAXONOMY.md §4.2)
 * - typicalDwellMinutes() → default visit duration for the reachability gate (PRD §10 step 8)
 */
enum PlaceType: string
{
    use HasOptions;

    // religious_sacred
    case Church = 'church';
    case Cathedral = 'cathedral';
    case Chapel = 'chapel';
    case Monastery = 'monastery';
    case Abbey = 'abbey';
    case Shrine = 'shrine';
    case Temple = 'temple';
    case SacredCemetery = 'sacred_cemetery';

    // historic_heritage
    case Castle = 'castle';
    case Fortress = 'fortress';
    case Ruin = 'ruin';
    case Monument = 'monument';
    case Memorial = 'memorial';
    case ArchaeologicalSite = 'archaeological_site';
    case HistoricHouse = 'historic_house';
    case CityGate = 'city_gate';
    case OldTown = 'old_town';

    // museum_gallery
    case ArtMuseum = 'art_museum';
    case HistoryMuseum = 'history_museum';
    case ScienceMuseum = 'science_museum';
    case LocalMuseum = 'local_museum';
    case HouseMuseum = 'house_museum';
    case Gallery = 'gallery';

    // nature_landscape
    case Viewpoint = 'viewpoint';
    case Park = 'park';
    case Garden = 'garden';
    case Forest = 'forest';
    case Waterfall = 'waterfall';
    case Lake = 'lake';
    case Beach = 'beach';
    case Cave = 'cave';
    case Cliff = 'cliff';
    case GeologicalFeature = 'geological_feature';
    case Spring = 'spring';

    // food_drink
    case Restaurant = 'restaurant';
    case Cafe = 'cafe';
    case Bakery = 'bakery';
    case Market = 'market';
    case FoodProducer = 'food_producer';
    case Winery = 'winery';
    case Brewery = 'brewery';
    case Distillery = 'distillery';
    case Deli = 'deli';

    // arts_culture
    case Theatre = 'theatre';
    case ConcertHall = 'concert_hall';
    case Cinema = 'cinema';
    case CulturalCenter = 'cultural_center';
    case StreetArt = 'street_art';
    case ArtistStudio = 'artist_studio';

    // architecture_urban
    case NotableBuilding = 'notable_building';
    case Square = 'square';
    case Bridge = 'bridge';
    case Tower = 'tower';
    case Fountain = 'fountain';
    case NotableStreet = 'notable_street';

    // shops_craft
    case ArtisanWorkshop = 'artisan_workshop';
    case Bookshop = 'bookshop';
    case AntiqueShop = 'antique_shop';
    case SpecialtyShop = 'specialty_shop';
    case CraftStudio = 'craft_studio';

    // activity_recreation
    case WalkingTrail = 'walking_trail';
    case CyclingRoute = 'cycling_route';
    case BeachRecreation = 'beach_recreation';
    case SportsVenue = 'sports_venue';
    case Wellness = 'wellness';
    case BoatActivity = 'boat_activity';

    // events
    case Concert = 'concert';
    case Festival = 'festival';
    case MarketDay = 'market_day';
    case Exhibition = 'exhibition';
    case Performance = 'performance';
    case SeasonalEvent = 'seasonal_event';

    // practical (Phase 2 — cases exist, no Phase 1 scout uses them)
    case Toilet = 'toilet';
    case ChargingPoint = 'charging_point';
    case Pharmacy = 'pharmacy';
    case Shelter = 'shelter';
    case TransportHub = 'transport_hub';

    public function domain(): PlaceTypeDomain
    {
        return match ($this) {
            self::Church, self::Cathedral, self::Chapel, self::Monastery,
            self::Abbey, self::Shrine, self::Temple, self::SacredCemetery => PlaceTypeDomain::ReligiousSacred,

            self::Castle, self::Fortress, self::Ruin, self::Monument, self::Memorial,
            self::ArchaeologicalSite, self::HistoricHouse, self::CityGate, self::OldTown => PlaceTypeDomain::HistoricHeritage,

            self::ArtMuseum, self::HistoryMuseum, self::ScienceMuseum,
            self::LocalMuseum, self::HouseMuseum, self::Gallery => PlaceTypeDomain::MuseumGallery,

            self::Viewpoint, self::Park, self::Garden, self::Forest, self::Waterfall, self::Lake,
            self::Beach, self::Cave, self::Cliff, self::GeologicalFeature, self::Spring => PlaceTypeDomain::NatureLandscape,

            self::Restaurant, self::Cafe, self::Bakery, self::Market, self::FoodProducer,
            self::Winery, self::Brewery, self::Distillery, self::Deli => PlaceTypeDomain::FoodDrink,

            self::Theatre, self::ConcertHall, self::Cinema,
            self::CulturalCenter, self::StreetArt, self::ArtistStudio => PlaceTypeDomain::ArtsCulture,

            self::NotableBuilding, self::Square, self::Bridge,
            self::Tower, self::Fountain, self::NotableStreet => PlaceTypeDomain::ArchitectureUrban,

            self::ArtisanWorkshop, self::Bookshop, self::AntiqueShop,
            self::SpecialtyShop, self::CraftStudio => PlaceTypeDomain::ShopsCraft,

            self::WalkingTrail, self::CyclingRoute, self::BeachRecreation,
            self::SportsVenue, self::Wellness, self::BoatActivity => PlaceTypeDomain::ActivityRecreation,

            self::Concert, self::Festival, self::MarketDay,
            self::Exhibition, self::Performance, self::SeasonalEvent => PlaceTypeDomain::Events,

            self::Toilet, self::ChargingPoint, self::Pharmacy,
            self::Shelter, self::TransportHub => PlaceTypeDomain::Practical,
        };
    }

    /** @return list<AppealFacet> rule-based priors (TAXONOMY.md §4.2) — a floor, not the final set. */
    public function baseFacets(): array
    {
        return match ($this) {
            self::Church, self::Cathedral, self::Chapel => [AppealFacet::Spiritual, AppealFacet::Architecture, AppealFacet::History],
            self::Monastery, self::Abbey => [AppealFacet::Spiritual, AppealFacet::History, AppealFacet::Architecture],
            self::Shrine, self::Temple => [AppealFacet::Spiritual, AppealFacet::History],
            self::SacredCemetery => [AppealFacet::Spiritual, AppealFacet::History, AppealFacet::Offbeat],

            self::Castle, self::Fortress, self::Ruin => [AppealFacet::History, AppealFacet::Architecture, AppealFacet::Scenic],
            self::Monument, self::Memorial => [AppealFacet::History],
            self::ArchaeologicalSite => [AppealFacet::History, AppealFacet::Educational],
            self::HistoricHouse, self::CityGate => [AppealFacet::History, AppealFacet::Architecture],
            self::OldTown => [AppealFacet::History, AppealFacet::Architecture, AppealFacet::LocalLife],

            self::ArtMuseum, self::Gallery => [AppealFacet::Art, AppealFacet::Educational],
            self::HistoryMuseum, self::HouseMuseum => [AppealFacet::History, AppealFacet::Educational],
            self::ScienceMuseum => [AppealFacet::Educational, AppealFacet::Family],
            self::LocalMuseum => [AppealFacet::History, AppealFacet::LocalLife, AppealFacet::Educational],

            self::Viewpoint, self::Cliff => [AppealFacet::Scenic, AppealFacet::Nature],
            self::Park, self::Garden => [AppealFacet::Nature, AppealFacet::Family],
            self::Forest => [AppealFacet::Nature, AppealFacet::Active],
            self::Waterfall, self::Lake => [AppealFacet::Nature, AppealFacet::Scenic, AppealFacet::Active],
            self::Beach => [AppealFacet::Nature, AppealFacet::Family, AppealFacet::Active],
            self::Cave, self::GeologicalFeature, self::Spring => [AppealFacet::Nature, AppealFacet::Offbeat],

            self::Restaurant => [AppealFacet::FoodDrink],
            self::Cafe, self::Bakery, self::Deli => [AppealFacet::FoodDrink, AppealFacet::LocalLife],
            self::Market => [AppealFacet::FoodDrink, AppealFacet::LocalLife],
            self::FoodProducer, self::Winery, self::Brewery, self::Distillery => [AppealFacet::FoodDrink, AppealFacet::Craft, AppealFacet::LocalLife],

            self::Theatre, self::ConcertHall, self::Cinema => [AppealFacet::Art],
            self::CulturalCenter => [AppealFacet::Art, AppealFacet::LocalLife],
            self::StreetArt => [AppealFacet::Art, AppealFacet::Offbeat],
            self::ArtistStudio => [AppealFacet::Art, AppealFacet::Craft],

            self::NotableBuilding, self::Tower, self::Bridge => [AppealFacet::Architecture],
            self::Square, self::NotableStreet => [AppealFacet::Architecture, AppealFacet::LocalLife],
            self::Fountain => [AppealFacet::Architecture, AppealFacet::Scenic],

            self::ArtisanWorkshop, self::CraftStudio => [AppealFacet::Craft, AppealFacet::LocalLife],
            self::Bookshop, self::SpecialtyShop => [AppealFacet::LocalLife],
            self::AntiqueShop => [AppealFacet::Offbeat, AppealFacet::LocalLife],

            self::WalkingTrail => [AppealFacet::Active, AppealFacet::Nature],
            self::CyclingRoute => [AppealFacet::Active, AppealFacet::Nature],
            self::BeachRecreation => [AppealFacet::Active, AppealFacet::Family, AppealFacet::Nature],
            self::SportsVenue, self::Wellness => [AppealFacet::Active],
            self::BoatActivity => [AppealFacet::Active, AppealFacet::Scenic],

            self::Concert, self::Performance => [AppealFacet::Art],
            self::Festival => [AppealFacet::LocalLife, AppealFacet::Art],
            self::MarketDay => [AppealFacet::FoodDrink, AppealFacet::LocalLife],
            self::Exhibition => [AppealFacet::Art, AppealFacet::Educational],
            self::SeasonalEvent => [AppealFacet::LocalLife],

            self::Toilet, self::ChargingPoint, self::Pharmacy, self::Shelter, self::TransportHub => [],
        };
    }

    /** Default visit duration in minutes, for the reachability gate (PRD §10 step 8). */
    public function typicalDwellMinutes(): int
    {
        return match ($this) {
            self::Chapel, self::Shrine, self::Spring => 15,
            self::Church => 20,
            self::Cathedral, self::HistoricHouse, self::HouseMuseum, self::Gallery,
            self::Ruin, self::Park, self::Garden, self::Cave, self::Market, self::FoodProducer => 45,
            self::Monastery, self::Abbey, self::ArchaeologicalSite, self::LocalMuseum, self::Lake,
            self::Winery, self::Brewery, self::Distillery, self::CulturalCenter, self::MarketDay => 60,
            self::Temple, self::SacredCemetery, self::Tower, self::Waterfall,
            self::ArtisanWorkshop, self::CraftStudio, self::ArtistStudio, self::NotableStreet, self::ChargingPoint => 30,
            self::Castle, self::ArtMuseum, self::HistoryMuseum, self::Restaurant,
            self::Forest, self::WalkingTrail, self::Wellness, self::BoatActivity, self::SeasonalEvent => 90,
            self::Fortress => 60,
            self::Monument, self::Memorial, self::CityGate, self::Bridge, self::Fountain, self::Bakery, self::Pharmacy => 10,
            self::OldTown, self::ScienceMuseum, self::Beach, self::BeachRecreation,
            self::CyclingRoute, self::SportsVenue, self::ConcertHall, self::Concert, self::Performance, self::Festival => 120,
            self::Viewpoint, self::Deli, self::StreetArt, self::NotableBuilding, self::Shelter, self::TransportHub => 15,
            self::Cliff, self::GeologicalFeature, self::Square, self::SpecialtyShop => 20,
            self::Cafe => 30,
            self::Bookshop, self::AntiqueShop => 25,
            self::Cinema => 135,
            self::Theatre => 150,
            self::Exhibition => 75,
            self::Toilet => 5,
        };
    }

    /**
     * Does this place stop being worth the walk once the light goes? (E16)
     *
     * The honest closing time for a viewpoint is sunset — nobody wants to be sent
     * twenty minutes uphill to look at a black bay. That gives these types a real
     * `last_feasible_start` (SCORING §4.3) where they would otherwise be evergreen
     * and score no urgency at all.
     *
     * Listed one by one rather than by domain, because the domain is the wrong
     * granularity: a cave is `nature_landscape` and is dark at noon, a street-art
     * wall is `arts_culture` and is useless after dusk. What matters is whether the
     * daylight IS the experience.
     */
    public function needsDaylight(): bool
    {
        return match ($this) {
            self::Viewpoint, self::Park, self::Garden, self::Forest, self::Waterfall,
            self::Lake, self::Beach, self::Cliff, self::GeologicalFeature, self::Spring,
            self::WalkingTrail, self::CyclingRoute, self::BeachRecreation,
            self::StreetArt, self::Ruin, self::ArchaeologicalSite => true,
            default => false,
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
