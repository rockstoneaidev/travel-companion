<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Context\Actions\EraseContextLocations;
use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Context\Contracts\Routing;
use App\Domain\Context\Contracts\SessionPositions;
use App\Domain\Context\Queries\LatestSessionPosition;
use App\Domain\Context\Services\FallbackRouting;
use App\Domain\Context\Services\GoogleRoutes;
use App\Domain\Notifications\Contracts\PushSender;
use App\Domain\Notifications\Services\LogPushSender;
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
use App\Domain\Recommendations\Actions\EraseRecommendationAnchors;
use App\Domain\Recommendations\Actions\RecordFeedbackForRecommendation;
use App\Domain\Recommendations\Contracts\FeedbackRecorder;
use App\Domain\Recommendations\Contracts\RecommendationTraceEraser;
use App\Domain\Trips\Actions\EraseTripLocations;
use App\Domain\Trips\Contracts\ExploreSessionLookup;
use App\Domain\Trips\Contracts\TripLocationEraser;
use App\Domain\Trips\Contracts\TripLookup;
use App\Domain\Trips\Queries\FindExploreSession;
use App\Domain\Trips\Queries\FindTrip;
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

        // ...and "is the companion switched on for this trip?", which Context must ask
        // before it stores a single background ping (E29).
        TripLookup::class => FindTrip::class,
        TripLocationEraser::class => EraseTripLocations::class,

        // Privacy — may we learn a taste profile at all? (Art. 9(2)(a), DPIA §3.2).
        // Asked by the LEARNER, which is the one place a weight can actually move.
        ProfilingConsent::class => UserProfilingConsent::class,

        // ...and the other direction: withdrawing consent must delete the profile, and
        // Privacy asks Profiles to do it through a contract rather than reaching in.
        TasteProfileEraser::class => ResetTasteProfile::class,

        // Context — the Context half of trip-level location erasure (PRD §16).
        ContextLocationEraser::class => EraseContextLocations::class,

        // ...and "where is this session now?", which is what lets the feed re-anchor
        // when the user walks somewhere else (E46). Ranking asks Context rather than
        // reading `context_events`, so the home-zone suppression on the way IN cannot
        // be bypassed by a new reader.
        SessionPositions::class => LatestSessionPosition::class,

        // Recommendations — the trace half of trip-level location erasure. This seam
        // was left open by DeleteTripLocationHistory ("traces carry no coordinate
        // columns yet"); `recommendations.anchor` (E46) is the column that closed it.
        RecommendationTraceEraser::class => EraseRecommendationAnchors::class,

        /*
         * The push rail (E31). A PORT — FCM today, APNs direct if it ever has to be, and a
         * log line in development.
         *
         * The default is LogPushSender, and the default is the safe one: a misconfigured
         * environment that silently sends real notifications to real phones is a far worse
         * failure than one that silently sends none. Reaching FCM requires saying so out
         * loud (`FCM_PROJECT_ID`).
         */
        PushSender::class => LogPushSender::class,

        // ...and the way back: a push receipt is feedback like any other tap, and must land
        // in the SAME ledger. A separate notification-feedback table would split the learning
        // signal in half (E31).
        FeedbackRecorder::class => RecordFeedbackForRecommendation::class,

        // Stage-B routing is bound in register() — it depends on config, which the
        // auto-binding array cannot express (see below).
    ];

    /**
     * Stage-B routing, chosen by config (E43; PRD §10, DATA-SOURCES §9).
     *
     * A port, so switching engines is a binding change and nothing else. Default is Google;
     * `routing.driver=osrm` swaps in self-hosted OSRM with Google kept as a live fallback.
     * This is cost-triggered — flip it when the ledger says Google Routes spend justifies
     * running OSRM, not before.
     */
    public function register(): void
    {
        $this->app->bind(Routing::class, static fn ($app): Routing => match (config('routing.driver')) {
            'osrm' => $app->make(FallbackRouting::class),
            default => $app->make(GoogleRoutes::class),
        });
    }
}
