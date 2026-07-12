<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Context\Actions\EraseContextLocations;
use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Context\Contracts\Routing;
use App\Domain\Context\Services\GoogleRoutes;
use App\Domain\Places\Contracts\ExternalIdRegistry;
use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Queries\LookupPlaces;
use App\Domain\Places\Services\LookupPlaceImages;
use App\Domain\Places\Services\PlaceExternalIds;
use App\Domain\Privacy\Contracts\ProfilingConsent;
use App\Domain\Privacy\Services\UserProfilingConsent;
use App\Domain\Profiles\Actions\ResetTasteProfile;
use App\Domain\Profiles\Contracts\TasteProfileEraser;
use App\Domain\Trips\Actions\EraseTripLocations;
use App\Domain\Trips\Contracts\ExploreSessionLookup;
use App\Domain\Trips\Contracts\TripLocationEraser;
use App\Domain\Trips\Queries\FindExploreSession;
use Illuminate\Support\ServiceProvider;

/**
 * Cross-module contracts, bound to implementations, grouped by owning module
 * (conventions/01). A module's Contracts/ directory is its public API; this file
 * is the only place that knows which class satisfies it.
 */
final class DomainServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        // Places — geography lives here, so geo lookups do too.
        PlaceLookup::class => LookupPlaces::class,

        // ...and the cross-source ID concordance: Context needs a Google place_id to
        // verify hours with, and may hold that STRING, but never Places' models (E16).
        ExternalIdRegistry::class => PlaceExternalIds::class,

        // ...and the photos, which the feed needs and may hold, without ever touching
        // Places' models (E18/UI).
        PlaceImageLookup::class => LookupPlaceImages::class,

        // Trips — what other modules may know about a session / a trip's locations.
        ExploreSessionLookup::class => FindExploreSession::class,
        TripLocationEraser::class => EraseTripLocations::class,

        // Privacy — may we learn a taste profile at all? (Art. 9(2)(a), DPIA §3.2).
        // Asked by the LEARNER, which is the one place a weight can actually move.
        ProfilingConsent::class => UserProfilingConsent::class,

        // ...and the other direction: withdrawing consent must delete the profile, and
        // Privacy asks Profiles to do it through a contract rather than reaching in.
        TasteProfileEraser::class => ResetTasteProfile::class,

        // Context — the Context half of trip-level location erasure (PRD §16).
        ContextLocationEraser::class => EraseContextLocations::class,

        // Stage-B routing (PRD §10). A port, so self-hosted OSRM/Valhalla on our own
        // OSM extract is a swap and not a rewrite (DATA-SOURCES §9).
        Routing::class => GoogleRoutes::class,
    ];
}
