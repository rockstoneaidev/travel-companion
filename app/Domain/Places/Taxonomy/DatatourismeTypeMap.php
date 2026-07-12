<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

use App\Domain\Places\Enums\PlaceType;

/**
 * DATAtourisme ontology `type` → PlaceType (TAXONOMY §3).
 *
 * A DATAtourisme object carries a *list* of ontology classes, and — this is the
 * trap — the list is NOT ordered. The Centre Pompidou arrives as
 * `[LocalBusiness, PlaceOfInterest, Museum, CulturalSite, PointOfInterest]`:
 * the most generic class is last, the most specific is in the middle. Reading
 * the list first-to-last (or last-to-first) types the Pompidou as a generic
 * "cultural site", or worse, a "local business".
 *
 * So the priority lives HERE, in the declaration order of self::MAP, and we scan
 * the map rather than the object: the first mapped class the object happens to
 * carry wins. Museum is declared above CulturalSite, so the Pompidou is a museum.
 * Keep specific classes above generic ones when editing.
 *
 * What is deliberately NOT mapped — and this is the whole editorial judgement of
 * the adapter, not an oversight:
 *
 *   Accommodation · LodgingBusiness · Hotel · HotelTrade · HolidayResort
 *   ServiceProvider · Transporter · ShoppingCentreAndGallery · Store
 *
 * DATAtourisme is fed by regional tourism boards, and a tourism board's job
 * includes listing every hotel and estate agent in the département. Ours does
 * not. This product surfaces *somewhere to go*, and a hotel is where you sleep,
 * not an opportunity. Of a 500-POI Paris sample, 61 were accommodation and 84
 * were shops: mapping them would have made one in four recommendations a hotel.
 */
final class DatatourismeTypeMap
{
    /**
     * Most specific first — order matters, this is scanned in sequence.
     *
     * @var array<string, PlaceType>
     */
    private const MAP = [
        // Food & drink worth a detour (a winery is an opportunity; a chain café is not)
        'Winery' => PlaceType::Winery,
        'BreweryOrDistillery' => PlaceType::Brewery,
        'BistroOrWineBar' => PlaceType::Cafe,
        'BrasserieOrTavern' => PlaceType::Restaurant,
        'GourmetRestaurant' => PlaceType::Restaurant,
        'CafeOrCoffeeShop' => PlaceType::Cafe,
        'CafeOrTeahouse' => PlaceType::Cafe,
        'BarOrPub' => PlaceType::Cafe,
        'Bakery' => PlaceType::Bakery,
        'FoodProducer' => PlaceType::FoodProducer,
        'CoveredMarket' => PlaceType::Market,
        'Market' => PlaceType::Market,
        'Restaurant' => PlaceType::Restaurant,

        // Culture & heritage — the reason this source exists
        'Museum' => PlaceType::HistoryMuseum,
        'ArtGalleryOrExhibitionGallery' => PlaceType::Gallery,
        'InterpretationCentre' => PlaceType::CulturalCenter,
        'CulturalCentre' => PlaceType::CulturalCenter,
        'Theater' => PlaceType::Theatre,
        'Theatre' => PlaceType::Theatre,
        'Opera' => PlaceType::Theatre,
        'ConcertHall' => PlaceType::ConcertHall,
        'Cinema' => PlaceType::Cinema,
        'Castle' => PlaceType::Castle,
        'Citadel' => PlaceType::Fortress,
        'Fortification' => PlaceType::Fortress,
        'RemarkableBuilding' => PlaceType::NotableBuilding,
        'ArcheologicalSite' => PlaceType::ArchaeologicalSite,
        'ArchaeologicalSite' => PlaceType::ArchaeologicalSite,
        'RemembranceSite' => PlaceType::Memorial,
        'TechnicalHeritage' => PlaceType::NotableBuilding,
        'ReligiousSite' => PlaceType::Church,
        'Church' => PlaceType::Church,
        'Cathedral' => PlaceType::Cathedral,
        'Abbey' => PlaceType::Abbey,
        'CulturalSite' => PlaceType::CulturalCenter,

        // Craft — the artisan workshop is exactly the "opportunity, not a place"
        'CraftsmanShop' => PlaceType::ArtisanWorkshop,
        'ArtCraft' => PlaceType::ArtisanWorkshop,
        'BoutiqueOrLocalShop' => PlaceType::SpecialtyShop,

        // Nature & outdoors
        'ParkAndGarden' => PlaceType::Park,
        'Park' => PlaceType::Park,
        'Garden' => PlaceType::Garden,
        'NaturalHeritage' => PlaceType::GeologicalFeature,
        'Beach' => PlaceType::Beach,
        'Lake' => PlaceType::Lake,
        'Cave' => PlaceType::Cave,
        'Viewpoint' => PlaceType::Viewpoint,
        'HikingTrail' => PlaceType::WalkingTrail,
        'WalkingTour' => PlaceType::WalkingTrail,
        'CyclingTour' => PlaceType::CyclingRoute,

        // Leisure
        'SwimmingPool' => PlaceType::Wellness,
        'SpaAndWellness' => PlaceType::Wellness,
        'AmusementPark' => PlaceType::SportsVenue,
        'ThemePark' => PlaceType::SportsVenue,
        'StadiumOrArena' => PlaceType::SportsVenue,
    ];

    /**
     * @param  list<string>  $types  the object's ontology classes, in no order
     */
    public static function map(array $types): ?PlaceType
    {
        // Scan the MAP, not the object: our declaration order is the specificity
        // ranking, and the object's own order carries no information.
        foreach (self::MAP as $ontologyClass => $placeType) {
            if (in_array($ontologyClass, $types, true)) {
                return $placeType;
            }
        }

        return null;
    }
}
