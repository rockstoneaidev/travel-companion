<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Context\Actions\EraseContextLocations;
use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Queries\LookupPlaces;
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

        // Trips — what other modules may know about a session / a trip's locations.
        ExploreSessionLookup::class => FindExploreSession::class,
        TripLocationEraser::class => EraseTripLocations::class,

        // Context — the Context half of trip-level location erasure (PRD §16).
        ContextLocationEraser::class => EraseContextLocations::class,
    ];
}
