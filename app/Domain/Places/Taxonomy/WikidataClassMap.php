<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

use App\Domain\Places\Enums\PlaceType;

/**
 * Wikidata `instance of` (P31) QIDs → PlaceType (TAXONOMY §3). Strongest for
 * heritage/nature where OSM is thin. First mapped QID wins — Wikidata items
 * often carry several P31 values, ordered by significance on the item.
 */
final class WikidataClassMap
{
    private const MAP = [
        // religious_sacred
        'Q16970' => PlaceType::Church,       // church building
        'Q2977' => PlaceType::Cathedral,     // cathedral
        'Q108325' => PlaceType::Chapel,      // chapel
        'Q44613' => PlaceType::Monastery,    // monastery
        'Q160742' => PlaceType::Abbey,       // abbey
        'Q697295' => PlaceType::Shrine,      // shrine
        'Q39614' => PlaceType::SacredCemetery, // cemetery

        // historic_heritage
        'Q23413' => PlaceType::Castle,       // castle
        'Q57821' => PlaceType::Fortress,     // fortification
        'Q109607' => PlaceType::Ruin,        // ruins
        'Q4989906' => PlaceType::Monument,   // monument
        'Q5003624' => PlaceType::Memorial,   // memorial
        'Q839954' => PlaceType::ArchaeologicalSite, // archaeological site
        'Q35112127' => PlaceType::HistoricHouse,    // historic house
        'Q82117' => PlaceType::CityGate,     // city gate

        // museum_gallery
        'Q33506' => PlaceType::LocalMuseum,  // museum
        'Q207694' => PlaceType::ArtMuseum,   // art museum
        'Q588140' => PlaceType::ScienceMuseum, // science museum
        'Q1970365' => PlaceType::LocalMuseum,  // open-air museum (Skansen)
        'Q2087181' => PlaceType::HouseMuseum,  // historic house museum
        'Q1007870' => PlaceType::Gallery,    // art gallery

        // nature_landscape
        'Q6017969' => PlaceType::Viewpoint,  // scenic viewpoint
        'Q22698' => PlaceType::Park,         // park
        'Q167346' => PlaceType::Garden,      // botanical garden
        'Q1107656' => PlaceType::Garden,     // garden
        'Q34038' => PlaceType::Waterfall,    // waterfall
        'Q23397' => PlaceType::Lake,         // lake
        'Q40080' => PlaceType::Beach,        // beach
        'Q35509' => PlaceType::Cave,         // cave

        // food_drink
        'Q11707' => PlaceType::Restaurant,   // restaurant
        'Q30022' => PlaceType::Cafe,         // café
        'Q274393' => PlaceType::Bakery,      // bakery
        'Q330284' => PlaceType::Market,      // marketplace
        'Q156362' => PlaceType::Winery,      // winery
        'Q131734' => PlaceType::Brewery,     // brewery

        // arts_culture
        'Q24354' => PlaceType::Theatre,      // theater
        'Q1060829' => PlaceType::ConcertHall, // concert hall
        'Q41253' => PlaceType::Cinema,       // movie theater
        'Q1329623' => PlaceType::CulturalCenter, // cultural center

        // built_environment
        'Q12518' => PlaceType::Tower,        // tower
        'Q12280' => PlaceType::Bridge,       // bridge
        'Q174782' => PlaceType::Square,      // square
        'Q483453' => PlaceType::Fountain,    // fountain
    ];

    /** @param list<string> $qids */
    public static function map(array $qids): ?PlaceType
    {
        foreach ($qids as $qid) {
            if (isset(self::MAP[$qid])) {
                return self::MAP[$qid];
            }
        }

        return null;
    }
}
