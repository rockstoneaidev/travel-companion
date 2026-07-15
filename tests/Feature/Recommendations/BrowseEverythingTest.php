<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Enums\ServeReason;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E51 — "Gimme more"
|--------------------------------------------------------------------------
|
| Five is the INTERRUPTION budget: how many things are worth putting in front of somebody
| who did not ask. It was never a limit on what a person may LOOK AT, and using one number
| for both makes the product an authority it has not earned.
|
| The pipeline was already scoring every reachable candidate and dropping all but five on
| the floor. The whole feature is: stop dropping them.
|
*/

function browsePlace(string $name, float $lat, float $lng, string $type, string $domain): Place
{
    $place = Place::factory()->create([
        'name' => $name,
        'type' => $type,
        'type_domain' => $domain,
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
        'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    return $place;
}

function browseSession(): ExploreSession
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    return ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);
}

beforeEach(function () {
    // Twenty walkable places. A feed can show five of them.
    for ($i = 0; $i < 20; $i++) {
        browsePlace(
            "Place {$i}",
            59.3103 + ($i % 5) * 0.0015,
            18.0227 + intdiv($i, 5) * 0.0015,
            $i % 2 === 0 ? 'gallery' : 'lake',
            $i % 2 === 0 ? 'arts_culture' : 'nature_landscape',
        );
    }
});

it('shows you everything it could reach, not the menu it chose', function () {
    $session = browseSession();
    $rank = app(RankSession::class);

    $feed = $rank->feedFor(ExploreSessionData::fromModel($session));
    $browse = $rank->browse(ExploreSessionData::fromModel($session), 50);

    // The feed is one menu's worth.
    expect($feed)->toHaveCount((int) config('trips.session.feed_size'));

    // The browse list is the candidate set — and it is much bigger, because it always was.
    // These places were scored, gated, evidence-checked and then thrown away on every
    // single pull. Nobody was ever shown them.
    expect($browse['total'])->toBeGreaterThan(10)
        ->and($browse['items'])->toHaveCount($browse['total']);
});

it('ranks the browse list without the feed’s diversity editing', function () {
    $session = browseSession();

    $items = app(RankSession::class)->browse(ExploreSessionData::fromModel($session), 50)['items'];

    // Best first, monotonically.
    $scores = array_column($items, 'composite');
    $sorted = $scores;
    rsort($sorted);

    expect($scores)->toBe($sorted);

    /*
     * And NOT diversity-shuffled. The repetition penalty is a property of a FEED (SCORING
     * §5.2) — it exists so five cards are not five churches. A browse list is the candidate
     * set itself, and penalising the fourth gallery there would be the system quietly
     * editing what the user explicitly asked to see for themselves. Which is the exact
     * paternalism this screen exists to undo.
     *
     * Ten galleries in a row at the top is a fine answer if ten galleries are the ten best
     * things around you. The user can see that, and judge it.
     */
    expect(count($items))->toBeGreaterThan(5);
});

it('costs nothing to look — no rows, no opportunities, no LLM', function () {
    Queue::fake();

    $session = browseSession();

    $browse = app(RankSession::class)->browse(ExploreSessionData::fromModel($session), 100);

    expect($browse['items'])->not->toBeEmpty();

    /*
     * This is the discipline that makes a hundred-item list affordable. A browse item is a
     * scored candidate and nothing more.
     *
     * A hundred rows of generated LLM copy for a list somebody is *scrolling past* would be
     * the most expensive imaginable way to be ignored — and it would put a hundred
     * recommendation rows into the decision trace for a decision nobody made.
     */
    expect(Recommendation::query()->count())->toBe(0)
        ->and(DB::table('opportunities')->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('makes the one they choose real — trace, voice and all', function () {
    $session = browseSession();
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $feed = $rank->feedFor($data);   // the usual five

    $servedIds = array_map(static fn ($r): string => $r->score_inputs['candidate']['place_id'], $feed);

    // Something the feed did NOT choose for them — the whole point of the screen is the
    // places our five-item budget had no room for.
    $chosen = collect($rank->browse($data, 50)['items'])
        ->reject(fn (array $i): bool => in_array($i['place_id'], $servedIds, true))
        ->first();

    expect($chosen)->not->toBeNull();

    $recommendation = $rank->open($data, $chosen['place_id']);

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->serve_reason)->toBe(ServeReason::Browse);

    /*
     * It is a REAL recommendation: it has an opportunity, a full decision trace, and every
     * sub-score that produced it. Without this, browse would be a dead end — no "why did I
     * get this", nothing for keep or dismiss to attach to, and every signal the user gave
     * it silently dropped.
     */
    expect($recommendation->opportunity_id)->not->toBeNull()
        ->and($recommendation->scores)->toHaveKey('composite')
        ->and($recommendation->score_inputs['candidate']['place_id'])->toBe($chosen['place_id']);
});

it('appends the chosen one to the menu instead of replacing it', function () {
    $session = browseSession();
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $feed = $rank->feedFor($data);
    $group = $feed[0]->serve_group;

    $servedIds = array_map(static fn ($r): string => $r->score_inputs['candidate']['place_id'], $feed);

    $unserved = collect($rank->browse($data, 50)['items'])
        ->reject(fn (array $i): bool => in_array($i['place_id'], $servedIds, true))
        ->first();

    $opened = $rank->open($data, $unserved['place_id']);

    /*
     * The user did not move and we did not re-rank — they reached into the candidate set we
     * already had and pulled one out. Starting a new serve batch would throw away the five
     * cards they were looking at a moment ago, which is the exact opposite of what "show me
     * more" means.
     */
    expect($opened->serve_group)->toBe($group)
        ->and($opened->position)->toBeGreaterThan(5);
});

it('returns the card they already have rather than serving it twice', function () {
    $session = browseSession();
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $feed = $rank->feedFor($data);
    $alreadyServed = $feed[0]->score_inputs['candidate']['place_id'];

    $before = Recommendation::query()->count();
    $opened = $rank->open($data, $alreadyServed);

    // It is on their screen already. Minting a second recommendation for it would double it
    // in the trace, double it in the cost ledger, and give the learner two votes for one tap.
    expect($opened->id)->toBe($feed[0]->id)
        ->and(Recommendation::query()->count())->toBe($before);
});
