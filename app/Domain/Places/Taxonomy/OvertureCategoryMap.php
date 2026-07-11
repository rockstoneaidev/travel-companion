<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

use App\Domain\Places\Enums\PlaceType;

/**
 * Overture primary category → PlaceType (TAXONOMY §3): the ~2,000-leaf Overture
 * scheme collapsed onto our ~65 leaves. Reference data, versioned under
 * Taxonomy::VERSION; unmapped categories return null and the raw category is
 * retained on the candidate for later re-normalisation.
 */
final class OvertureCategoryMap
{
    private const MAP = [
        // religious_sacred
        'church' => PlaceType::Church,
        'cathedral' => PlaceType::Cathedral,
        'chapel' => PlaceType::Chapel,
        'monastery' => PlaceType::Monastery,
        'mosque' => PlaceType::Temple,
        'synagogue' => PlaceType::Temple,
        'buddhist_temple' => PlaceType::Temple,
        'hindu_temple' => PlaceType::Temple,
        'religious_place' => PlaceType::Church,

        // historic_heritage
        'castle' => PlaceType::Castle,
        'fort' => PlaceType::Fortress,
        'historical_landmark' => PlaceType::Monument,
        'monument' => PlaceType::Monument,
        'memorial_site' => PlaceType::Memorial,
        'archaeological_site' => PlaceType::ArchaeologicalSite,

        // museum_gallery
        'museum' => PlaceType::LocalMuseum,
        'art_museum' => PlaceType::ArtMuseum,
        'history_museum' => PlaceType::HistoryMuseum,
        'science_museum' => PlaceType::ScienceMuseum,
        'art_gallery' => PlaceType::Gallery,

        // nature_landscape
        'park' => PlaceType::Park,
        'garden' => PlaceType::Garden,
        'botanical_garden' => PlaceType::Garden,
        'beach' => PlaceType::Beach,
        'waterfall' => PlaceType::Waterfall,
        'cave' => PlaceType::Cave,
        'scenic_point' => PlaceType::Viewpoint,
        'forest' => PlaceType::Forest,
        'lake' => PlaceType::Lake,

        // food_drink
        'restaurant' => PlaceType::Restaurant,
        'cafe' => PlaceType::Cafe,
        'coffee_shop' => PlaceType::Cafe,
        'bakery' => PlaceType::Bakery,
        'farmers_market' => PlaceType::Market,
        'marketplace' => PlaceType::Market,
        'winery' => PlaceType::Winery,
        'brewery' => PlaceType::Brewery,
        'distillery' => PlaceType::Distillery,
        'delicatessen' => PlaceType::Deli,

        // arts_culture
        'theater' => PlaceType::Theatre,
        'concert_hall' => PlaceType::ConcertHall,
        'movie_theater' => PlaceType::Cinema,
        'cultural_center' => PlaceType::CulturalCenter,
        'public_art' => PlaceType::StreetArt,

        // built_environment
        'landmark_and_historical_building' => PlaceType::NotableBuilding,
        'plaza' => PlaceType::Square,
        'bridge' => PlaceType::Bridge,
        'observation_tower' => PlaceType::Tower,
        'fountain' => PlaceType::Fountain,

        // craft_local_products
        'bookstore' => PlaceType::Bookshop,
        'antique_store' => PlaceType::AntiqueShop,
        'specialty_food_store' => PlaceType::SpecialtyShop,
        'arts_and_crafts_store' => PlaceType::CraftStudio,

        // active_recreation
        'hiking_trail' => PlaceType::WalkingTrail,
        'spa' => PlaceType::Wellness,
        'stadium_arena' => PlaceType::SportsVenue,
        'marina' => PlaceType::BoatActivity,

        // practical
        'pharmacy' => PlaceType::Pharmacy,
        'public_toilet' => PlaceType::Toilet,
        'ev_charging_station' => PlaceType::ChargingPoint,
    ];

    public static function map(string $category): ?PlaceType
    {
        return self::MAP[$category] ?? null;
    }
}
