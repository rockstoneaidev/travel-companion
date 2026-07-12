<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Privacy\Actions\DeleteTripLocationHistory;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\BuildDigest;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Queries\BuildJournal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E18 — the digest release valve (PRD §12.4) and the journal (SCREENS S7)
|--------------------------------------------------------------------------
|
| "Opportunities that don't clear the feed bar don't die."
|
| They were reachable, they cleared the evidence gates, they were scored — they
| simply lost to four better things. Those near-misses are the most valuable
| candidates nobody ever sees, and they were being dropped on the floor. The
| digest is what makes the feed's silence affordable.
|
*/

/** A served recommendation whose funnel remembers what it beat. */
function recommendationWithFunnel(User $user, Trip $trip, array $nearMisses, array $held = []): Recommendation
{
    return Recommendation::query()->create([
        'user_id' => $user->id,
        'trip_id' => $trip->id,
        'opportunity_id' => Opportunity::factory()->create(['status' => OpportunityStatus::Served])->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => [
            'candidate' => ['name' => 'The winner', 'lat' => 59.31, 'lng' => 18.02, 'facets' => []],
            'funnel' => ['near_misses' => $nearMisses, 'held' => $held, 'unreachable' => []],
        ],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);
}

function digestPlace(string $name, ?string $windowEndsAt = null): Opportunity
{
    return Opportunity::factory()->create([
        'status' => OpportunityStatus::Scored,
        'title' => $name,
        'summary' => "Something quiet about {$name}.",
        'window_ends_at' => $windowEndsAt,
        'expires_at' => now()->addDay(),
    ]);
}

beforeEach(function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => ['time' => [], 'precipitation' => []]])]);
});

it('surfaces what the feed passed over — the whole point of the valve', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $beaten = digestPlace('Färgfabriken');
    $gated = digestPlace('Trekanten');

    recommendationWithFunnel(
        $user,
        $trip,
        nearMisses: [['place_id' => $beaten->place_id, 'name' => 'Färgfabriken', 'composite' => 0.61]],
        held: [['place_id' => $gated->place_id, 'name' => 'Trekanten', 'hold' => 'evidence']],
    );

    $digest = app(BuildDigest::class)->forUser($user->id, CarbonImmutable::parse('2026-07-13 08:00'));

    $titles = array_map(static fn ($i): string => $i->title, $digest->items);

    // Both kinds of near-miss reach the digest: outranked AND held. Neither is a
    // failure — they just weren't worth an interrupt.
    expect($digest->variant)->toBe('morning')
        ->and($titles)->toContain('Färgfabriken')
        ->and($titles)->toContain('Trekanten');
});

it('will not put a closed window in "today near you"', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $closed = digestPlace('Midsummer concert', now()->subHours(2)->toDateTimeString());

    recommendationWithFunnel($user, $trip, nearMisses: [
        ['place_id' => $closed->place_id, 'name' => 'Midsummer concert', 'composite' => 0.7],
    ]);

    $digest = app(BuildDigest::class)->forUser($user->id, CarbonImmutable::now());

    // Silence beats a stale suggestion, here as everywhere else in this product.
    expect($digest->items)->toBeEmpty();
});

it('states the weather only when it actually knows it', function () {
    $this->actingAs($user = User::factory()->create());

    // No session, so no place to ask about. It says less rather than inventing a
    // city and a forecast — "dry until four" is a factual claim, and the LLM is
    // never a source of facts.
    $digest = app(BuildDigest::class)->forUser($user->id, CarbonImmutable::parse('2026-07-13 08:00'));

    expect($digest->lede)->toBe('Good morning.');
});

it('turns into a recap in the evening', function () {
    $this->actingAs($user = User::factory()->create());

    $digest = app(BuildDigest::class)->forUser($user->id, CarbonImmutable::parse('2026-07-13 19:00'));

    expect($digest->variant)->toBe('evening')
        ->and($digest->subline)->toContain('tonight');
});

it('renders the digest screen', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/digest/today')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('digest')->has('digest.items'));
});

it('remembers what you DID, not where you were', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id, 'name' => 'Stockholm, July']);

    $recommendation = recommendationWithFunnel($user, $trip, nearMisses: []);
    DB::table('recommendations')->where('id', $recommendation->id)->update([
        'score_inputs' => json_encode([
            'candidate' => ['name' => 'Färgfabriken', 'lat' => 59.31, 'lng' => 18.02],
            'funnel' => ['near_misses' => [], 'held' => [], 'unreachable' => []],
        ]),
    ]);

    $this->post("/recommendations/{$recommendation->id}/feedback", ['event' => 'visited']);

    // Erase every coordinate the trip holds (PRD §16) — the strongest privacy
    // request there is.
    app(DeleteTripLocationHistory::class)($trip->id);

    $journal = app(BuildJournal::class)->forUser($user->id);

    // ...and the memory SURVIVES it. The journal is built from the feedback ledger,
    // not from location history, so you can erase where you were and still keep
    // what you did. That is the version of "your travel memory belongs to you" that
    // actually means something.
    expect($journal)->toHaveCount(1)
        ->and($journal[0]['name'])->toBe('Stockholm, July')
        ->and($journal[0]['entries'])->toHaveCount(1)
        ->and($journal[0]['entries'][0]['title'])->toBe('Färgfabriken')
        ->and($journal[0]['entries'][0]['visited'])->toBeTrue();
});

it('renders the journal screen', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/journal')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('journal')->has('trips'));
});
