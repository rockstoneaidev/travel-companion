<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Actions\StartExploreSession;
use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The origin cell every session was missing
|--------------------------------------------------------------------------
|
| `explore_sessions.origin_h3_index` was created with the note "E5 fills this;
| E17 coarsens to it". E17 did its half. E5 never did, and nothing in app/ ever
| wrote the column — so the ONLY thing that set it was the nightly retention
| pass, which back-fills the cell at 30 days on its way to deleting the
| coordinate. Every live session carried a NULL cell for its entire useful life.
|
| That crashed the morning dashboard: BuildDigest hands the cell to the weather
| client, and `(string) null` is '', and `h3_cell_to_geometry(''::h3index)` is a
| Postgres error rather than a null. Two map tests had been failing on it.
|
*/

it('gives a session its res-8 cell when it starts, not a month later', function () {
    $user = User::factory()->create();

    $session = app(StartExploreSession::class)(new NewExploreSessionData(
        userId: $user->id,
        origin: new Coordinates(59.3103, 18.0227),
        timeBudgetMinutes: 180,
        travelMode: TravelMode::Walk,
    ));

    // The cell Postgres itself would assign — the same function, at the same resolution,
    // that the retention pass uses (conventions/12). One implementation of H3, not two.
    $expected = DB::selectOne(
        'SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS cell',
        [18.0227, 59.3103],
    )->cell;

    expect($session->refresh()->origin_h3_index)->toBe($expected);
});

it('serves the dashboard to someone whose session has no cell, rather than a 500', function () {
    // Defence in depth, and not a hypothetical state: erasure nulls the cell
    // (EraseTripLocations), and every row in the database was in this state until the
    // indexer was wired in. A missing cell must degrade the greeting, never fail the page.
    $user = profilingAsked(User::factory()->create());

    $session = ExploreSession::factory()->at(59.3293, 18.0686)->create(['user_id' => $user->id]);

    ExploreSession::query()->whereKey($session->id)->update(['origin_h3_index' => null]);

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
