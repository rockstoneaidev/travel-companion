<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

use App\Domain\Places\Enums\PlaceType;

/**
 * Base Mérimée `denomination_de_l_edifice` → PlaceType (TAXONOMY §3).
 *
 * The vocabulary is a French ministry's, not ours, and it is enormous — 46,714
 * protected buildings across hundreds of denominations. This maps the ones that
 * are actually *opportunities* and drops the rest.
 *
 * Dropping is the point. A protected `ferme` (farmhouse) or `immeuble`
 * (apartment block) is legally a Monument Historique and touristically nothing:
 * it is someone's home with a plaque. Serving those would bury the Sainte-Chapelle
 * under a thousand listed façades. An unmapped denomination yields null and the
 * item is skipped (conventions/09 — normalize() never invents a type).
 */
final class MerimeeDenominationMap
{
    /** @var array<string, PlaceType> */
    private const MAP = [
        // Religious
        'église' => PlaceType::Church,
        'église paroissiale' => PlaceType::Church,
        'cathédrale' => PlaceType::Cathedral,
        'basilique' => PlaceType::Church,
        'chapelle' => PlaceType::Chapel,
        'abbaye' => PlaceType::Abbey,
        'prieuré' => PlaceType::Monastery,
        'couvent' => PlaceType::Monastery,
        'monastère' => PlaceType::Monastery,
        'temple' => PlaceType::Temple,
        'synagogue' => PlaceType::Temple,
        'mosquée' => PlaceType::Temple,
        'cloître' => PlaceType::Monastery,

        // Defensive / castles
        'château' => PlaceType::Castle,
        'château fort' => PlaceType::Castle,
        'manoir' => PlaceType::Castle,
        'palais' => PlaceType::Castle,
        'édifice fortifié' => PlaceType::Fortress,
        'fortification d\'agglomération' => PlaceType::Fortress,
        'fort' => PlaceType::Fortress,
        'citadelle' => PlaceType::Fortress,
        'enceinte' => PlaceType::Fortress,
        'donjon' => PlaceType::Fortress,
        'tour' => PlaceType::Tower,
        'porte de ville' => PlaceType::CityGate,

        // Archaeology & megaliths — exactly the "things Google doesn't have"
        'site archéologique' => PlaceType::ArchaeologicalSite,
        'dolmen' => PlaceType::ArchaeologicalSite,
        'menhir' => PlaceType::ArchaeologicalSite,
        'tumulus' => PlaceType::ArchaeologicalSite,
        'oppidum' => PlaceType::ArchaeologicalSite,
        'aqueduc' => PlaceType::ArchaeologicalSite,
        'théâtre antique' => PlaceType::ArchaeologicalSite,
        'amphithéâtre' => PlaceType::ArchaeologicalSite,
        'arc de triomphe' => PlaceType::Monument,

        // Monuments & civic set pieces
        'monument' => PlaceType::Monument,
        'monument aux morts' => PlaceType::Memorial,
        'croix monumentale' => PlaceType::Monument,
        'croix de chemin' => PlaceType::Monument,
        'croix de cimetière' => PlaceType::Monument,
        'calvaire monumental' => PlaceType::Monument,
        'fontaine' => PlaceType::Fountain,
        'pont' => PlaceType::Bridge,
        'moulin' => PlaceType::NotableBuilding,
        'moulin à vent' => PlaceType::NotableBuilding,
        'hôtel de ville' => PlaceType::NotableBuilding,
        'beffroi' => PlaceType::Tower,
        'halle' => PlaceType::Market,

        // Cultural
        'théâtre' => PlaceType::Theatre,
        'opéra' => PlaceType::Theatre,
        'musée' => PlaceType::HistoryMuseum,
        'bibliothèque' => PlaceType::CulturalCenter,

        // Grand houses open enough to be worth a detour
        'hôtel' => PlaceType::HistoricHouse,      // "hôtel particulier" — a townhouse, not a hotel
        'demeure' => PlaceType::HistoricHouse,
        'villa' => PlaceType::HistoricHouse,

        // Deliberately NOT mapped, and this is a decision rather than an omission:
        //   maison · immeuble · ferme · magasin de commerce · presbytère ·
        //   hôpital · cimetière · lycée · usine
        // Protected, yes. An opportunity for a traveler, no — they are homes,
        // offices and schools with a plaque. Mapping them would drown the feed.
    ];

    public static function map(?string $denomination): ?PlaceType
    {
        if ($denomination === null) {
            return null;
        }

        $key = mb_strtolower(trim($denomination));

        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        // Mérimée qualifies freely: "église paroissiale Saint-Pierre",
        // "chapelle funéraire". Fall back to the leading noun, which is the
        // denomination's head word — but only as an exact head match, never a
        // substring search (a "maison du gardien du château" is a house).
        $head = explode(' ', $key)[0];

        return self::MAP[$head] ?? null;
    }
}
