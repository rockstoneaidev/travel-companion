<?php

declare(strict_types=1);

use App\Domain\Context\Actions\RecordContextEvent;
use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Events\SessionPositionMoved;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Privacy\Actions\UpdatePrivacySettings;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Listeners\LearnAreaOnPositionMoved;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Jobs\Ingest\FirstLightJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E48 follow-up — learning the ground you MOVED to, not just started on
|--------------------------------------------------------------------------
|
| The bug the founder hit in the emulator: drag the pin into an area we've never ingested
| and nothing happens — because the only trigger for "do we know here?" was session start,
| the door already walked through. Moving now asks the question again, for where you are now.
|
*/

function movingSession(): ExploreSession
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    return ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);
}

function moveTo(ExploreSession $session, float $lat, float $lng): void
{
    app(RecordContextEvent::class)(new NewContextEventData(
        exploreSessionId: $session->id,
        location: new Coordinates($lat, $lng),
        occurredAt: CarbonImmutable::now(),
    ));
}

it('asks "do we know here?" when the pin moves, not only when the session starts', function () {
    Event::fake([SessionPositionMoved::class]);

    $session = movingSession();
    moveTo($session, 65.585, 22.29);   // off to Hertsön

    // A move now dispatches the learn-check — the thing that used to fire only at session start.
    Event::assertDispatched(SessionPositionMoved::class, fn (SessionPositionMoved $e): bool => abs($e->lat - 65.585) < 0.001 && abs($e->lng - 22.29) < 0.001);
});

it('learns the area the traveller moved to, starting an ingest of genuinely unknown ground', function () {
    Queue::fake();
    $session = movingSession();

    // Kiruna: no catalogue region covers it, no places near it — genuinely unknown ground.
    app(LearnAreaOnPositionMoved::class)->handle(new SessionPositionMoved($session->id, 67.85, 20.22));

    // The move kicks off first light AND the region build for where the traveller now is —
    // exactly what session start does, now for every move after it.
    Queue::assertPushed(FirstLightJob::class);
    Queue::assertPushed(BuildRegionWorldModelJob::class);
});

it('throttles a burst of moves in the same spot to a single ingest', function () {
    Queue::fake();
    $session = movingSession();

    $listener = app(LearnAreaOnPositionMoved::class);
    for ($i = 0; $i < 5; $i++) {
        $listener->handle(new SessionPositionMoved($session->id, 67.8501 + $i * 0.0001, 20.2201));
    }

    // Five moves within one coarse patch collapse to ONE region build — the throttle and the
    // build lock together mean a dragged pin does not fan out five hours of Overpass.
    Queue::assertPushed(BuildRegionWorldModelJob::class, 1);
});

it('does not learn the area around home — a suppressed coordinate never dispatches', function () {
    Event::fake([SessionPositionMoved::class]);

    $user = User::factory()->create();
    app(UpdatePrivacySettings::class)->declareHomeZone((int) $user->id, 59.30, 18.00, 400);
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create(['trip_id' => $trip->id, 'user_id' => $user->id]);

    // A context event inside the home zone is suppressed to no-coordinate, so it must not
    // ask us to learn the neighbourhood the user lives in.
    moveTo($session, 59.30, 18.00);

    Event::assertNotDispatched(SessionPositionMoved::class);
});
