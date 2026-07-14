<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

use App\Domain\Places\Enums\PlaceType;

/**
 * OSM primary tags → PlaceType (TAXONOMY §3). Pure reference data, versioned
 * under Taxonomy::VERSION. Order matters: the most identity-carrying key wins
 * (a castle that contains a café is a castle).
 */
final class OsmTagMap
{
    /** @param array<string, string> $tags */
    public static function map(array $tags): ?PlaceType
    {
        return self::historic($tags)
            ?? self::tourism($tags)
            ?? self::natural($tags)
            ?? self::craft($tags)
            ?? self::amenity($tags)
            ?? self::leisure($tags)
            ?? self::manMade($tags)
            ?? self::shop($tags)
            ?? self::place($tags);
    }

    /** @param array<string, string> $tags */
    private static function historic(array $tags): ?PlaceType
    {
        return match ($tags['historic'] ?? null) {
            'castle' => PlaceType::Castle,
            'fort', 'fortress', 'citadel' => PlaceType::Fortress,
            'ruins' => PlaceType::Ruin,
            'monument' => PlaceType::Monument,
            'memorial', 'wayside_cross' => PlaceType::Memorial,
            'archaeological_site', 'rune_stone' => PlaceType::ArchaeologicalSite,
            'manor', 'house' => PlaceType::HistoricHouse,
            'city_gate' => PlaceType::CityGate,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function tourism(array $tags): ?PlaceType
    {
        return match ($tags['tourism'] ?? null) {
            'viewpoint' => PlaceType::Viewpoint,
            'museum' => match ($tags['museum'] ?? null) {
                'art' => PlaceType::ArtMuseum,
                'history' => PlaceType::HistoryMuseum,
                'science', 'technology' => PlaceType::ScienceMuseum,
                'local' => PlaceType::LocalMuseum,
                'house', 'person' => PlaceType::HouseMuseum,
                'open_air' => PlaceType::LocalMuseum,
                default => PlaceType::LocalMuseum,
            },
            'gallery' => PlaceType::Gallery,
            'artwork' => PlaceType::StreetArt,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function natural(array $tags): ?PlaceType
    {
        return match ($tags['natural'] ?? null) {
            'waterfall' => PlaceType::Waterfall,
            'beach' => PlaceType::Beach,
            'cave_entrance' => PlaceType::Cave,
            'cliff' => PlaceType::Cliff,
            'spring' => PlaceType::Spring,
            'wood' => PlaceType::Forest,
            'water' => ($tags['water'] ?? null) === 'lake' ? PlaceType::Lake : null,
            'rock', 'stone' => PlaceType::GeologicalFeature,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function craft(array $tags): ?PlaceType
    {
        return match ($tags['craft'] ?? null) {
            'winery' => PlaceType::Winery,
            'brewery' => PlaceType::Brewery,
            'distillery' => PlaceType::Distillery,
            'pottery', 'goldsmith', 'jeweller', 'leather', 'shoemaker', 'watchmaker',
            'glassblower', 'carpenter', 'bookbinder' => PlaceType::ArtisanWorkshop,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function amenity(array $tags): ?PlaceType
    {
        return match ($tags['amenity'] ?? null) {
            'place_of_worship' => self::worship($tags),
            'restaurant' => PlaceType::Restaurant,
            'cafe' => PlaceType::Cafe,
            'marketplace' => PlaceType::Market,
            'theatre' => PlaceType::Theatre,
            'concert_hall' => PlaceType::ConcertHall,
            'cinema' => PlaceType::Cinema,
            'arts_centre' => PlaceType::CulturalCenter,
            'fountain' => PlaceType::Fountain,
            'pharmacy' => PlaceType::Pharmacy,
            'toilets' => PlaceType::Toilet,
            'charging_station' => PlaceType::ChargingPoint,
            'shelter' => PlaceType::Shelter,
            'bus_station' => PlaceType::TransportHub,
            'ferry_terminal' => PlaceType::TransportHub,
            default => self::transport($tags),
        };
    }

    /**
     * Transport hubs (E39). Stations live under several OSM keys, not just `amenity`, so
     * this catches the ones `amenity()` did not — a railway station is
     * `railway=station`, a metro entrance is `railway=subway_entrance`, and a stop area is
     * `public_transport=station`.
     *
     * Deliberately NOT every bus stop and tram pole: `highway=bus_stop` is millions of
     * roadside signs, and a companion pointing at each one is noise. A hub is a place you
     * would route *to* — a station, a terminal — not a pole you pass.
     *
     * @param  array<string, string>  $tags
     */
    private static function transport(array $tags): ?PlaceType
    {
        return match (true) {
            in_array($tags['railway'] ?? null, ['station', 'halt'], true) => PlaceType::TransportHub,
            ($tags['public_transport'] ?? null) === 'station' => PlaceType::TransportHub,
            ($tags['amenity'] ?? null) === 'bus_station' => PlaceType::TransportHub,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function worship(array $tags): PlaceType
    {
        $building = $tags['building'] ?? null;

        return match (true) {
            $building === 'cathedral' => PlaceType::Cathedral,
            $building === 'chapel' => PlaceType::Chapel,
            $building === 'monastery' => PlaceType::Monastery,
            $building === 'temple', in_array($tags['religion'] ?? null, ['buddhist', 'hindu'], true) => PlaceType::Temple,
            $building === 'shrine' => PlaceType::Shrine,
            default => PlaceType::Church,
        };
    }

    /** @param array<string, string> $tags */
    private static function leisure(array $tags): ?PlaceType
    {
        return match ($tags['leisure'] ?? null) {
            'park' => PlaceType::Park,
            'garden' => PlaceType::Garden,
            'beach_resort' => PlaceType::BeachRecreation,
            'sports_centre', 'stadium' => PlaceType::SportsVenue,
            'sauna' => PlaceType::Wellness,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function manMade(array $tags): ?PlaceType
    {
        return match ($tags['man_made'] ?? null) {
            'tower' => PlaceType::Tower,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function shop(array $tags): ?PlaceType
    {
        return match ($tags['shop'] ?? null) {
            'bakery' => PlaceType::Bakery,
            'deli', 'cheese' => PlaceType::Deli,
            'books' => PlaceType::Bookshop,
            'antiques' => PlaceType::AntiqueShop,
            'chocolate', 'confectionery', 'coffee', 'tea', 'wine' => PlaceType::SpecialtyShop,
            default => null,
        };
    }

    /** @param array<string, string> $tags */
    private static function place(array $tags): ?PlaceType
    {
        return match ($tags['place'] ?? null) {
            'square' => PlaceType::Square,
            default => null,
        };
    }
}
