<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Events\ExploreSessionStarted;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/explore-sessions` — the one thing the user initiates (PRD §6.6).
 *
 * The session is top-level; the trip is resolved-or-created behind it. Both the
 * Inertia controller and the API controller call this, and nothing else creates
 * a session.
 */
final class StartExploreSession
{
    public function __construct(
        private readonly ResolveOrCreateTripForSession $resolveTrip,
        private readonly TileIndexer $tiles,
    ) {}

    public function __invoke(NewExploreSessionData $data): ExploreSession
    {
        return DB::transaction(function () use ($data): ExploreSession {
            $startedAt = $data->startedAt();

            /*
             * A PERSON IS IN ONE PLACE AT A TIME.
             *
             * Nothing used to stop a second session opening while one was already live,
             * and it happened: two sessions a minute apart, both "active", because the
             * start form was submitted twice. The rest of the app assumes there is
             * exactly one — the dashboard resumes FindActiveExploreSessionForUser, which
             * takes the LATEST active session — so the older one becomes invisible while
             * remaining real: still scouted for, still expiring, still costing money, and
             * unreachable from any screen.
             *
             * So starting a session closes whatever was open. It is not a double-submit
             * guard (that would silently swallow a deliberate restart); it is the
             * invariant stated where it can be enforced.
             */
            ExploreSession::query()
                ->where('user_id', $data->userId)
                ->where('status', ExploreSessionStatus::Active)
                ->update([
                    'status' => ExploreSessionStatus::Ended,
                    'ended_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]);

            $trip = ($this->resolveTrip)($data->userId, $data->origin, $startedAt, $data->contextSource);

            /*
             * The res-8 cell, written at creation — the seam the migration promised
             * ("`origin_h3_index`: E5 fills this; E17 coarsens to it") and that E5 never
             * actually wired.
             *
             * Nothing in app/ wrote this column. The ONLY thing that ever set it was the
             * nightly retention pass, which back-fills it from `origin` at 30 days on its
             * way to deleting the coordinate — so a live session carried a NULL cell for
             * its entire useful life, and only acquired one a month after it stopped
             * mattering. Every session in the database was in that state.
             *
             * That is not a cosmetic gap. `BuildDigest::lede()` reads this column and
             * hands it to the weather client, so the morning dashboard raised a Postgres
             * error (`h3_cell_to_geometry(''::h3index)`) for every user who had ever
             * started a session. The map tests were failing on it; the tests were right.
             */
            $session = ExploreSession::query()->create([
                'user_id' => $data->userId,
                'trip_id' => $trip->id,
                'origin' => $data->origin,
                'origin_h3_index' => $this->tiles->cellFor($data->origin->lat, $data->origin->lng),
                'time_budget_minutes' => $data->timeBudgetMinutes,
                'travel_mode' => $data->travelMode,
                'heading' => $data->heading,
                'destination_point' => $data->destinationPoint,
                'status' => ExploreSessionStatus::Active,
                'context_source' => $data->contextSource,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->addMinutes($data->timeBudgetMinutes),
            ]);

            // PRD §10's event vocabulary. Nothing listens yet: the scouts that
            // will (E5) don't exist. Emitting from day one is what makes them
            // additive rather than a rewrite.
            ExploreSessionStarted::dispatch($session->id, $trip->id, $data->userId);

            return $session;
        });
    }
}
