<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Services\MaterializeAlertOpportunities;
use App\Domain\Places\Models\Place;
use App\Domain\Sources\Data\LocalAlert;
use App\Domain\Sources\Enums\LocalAlertKind;
use App\Domain\Sources\Services\AlertClassifier;
use App\Domain\Sources\Services\NewsFeedReader;
use App\Enums\SourceLicense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E39 — the local layer: closures, strikes, disruptions
|--------------------------------------------------------------------------
|
| A companion that stays silent while the coast road is shut is not a companion. But the
| way it learns the road is shut is load-bearing: the fact must be the newspaper's, not the
| model's (non-negotiable #3), and the alert must be pinned to a real place or dropped —
| never guessed onto a map.
|
*/

it('reads the vocabulary of disruption, in the region’s own language', function () {
    $c = new AlertClassifier;

    // French, because the France corridor is a pilot region.
    expect($c->classify('Promenade des Anglais fermée pour travaux', 'fr'))->toBe(LocalAlertKind::Closure)
        ->and($c->classify('Grève des transports lundi', 'fr'))->toBe(LocalAlertKind::Strike)
        ->and($c->classify('Alerte inondation sur le Var', 'fr'))->toBe(LocalAlertKind::Hazard);

    // Swedish, the test region.
    expect($c->classify('Vägen avstängd efter vägarbete', 'sv'))->toBe(LocalAlertKind::Closure)
        ->and($c->classify('Tågstrejk på måndag', 'sv'))->toBe(LocalAlertKind::Strike);
});

it('says nothing about news that is not a disruption', function () {
    $c = new AlertClassifier;

    // Most local news. The correct answer is null, and it is null without apology.
    expect($c->classify('Ouverture d’un nouveau restaurant sur le port', 'fr'))->toBeNull()
        ->and($c->classify('Le festival de jazz revient cet été', 'fr'))->toBeNull()
        ->and($c->classify('New bakery opens on the high street', 'en'))->toBeNull();
});

it('calls a strike a strike even when it also closes something', function () {
    // "Station shut BY strike" is a strike — the cause is the thing still true tomorrow.
    expect((new AlertClassifier)->classify('Gare fermée en raison de la grève', 'fr'))
        ->toBe(LocalAlertKind::Strike);
});

it('keeps only the disruptions from a real feed, with a link and never the article', function () {
    $reader = new NewsFeedReader(new AlertClassifier);

    $xml = file_get_contents(base_path('tests/Fixtures/Sources/news-nice-matin.xml'));
    $alerts = $reader->parse($xml, 'news_local', 'Nice-Matin', 'fr');

    // Three items in the feed; two are disruptions; the restaurant opening is dropped.
    expect($alerts)->toHaveCount(2);

    $closure = $alerts[0];
    expect($closure->kind)->toBe(LocalAlertKind::Closure)
        ->and($closure->title)->toContain('Promenade des Anglais')
        // The LINK is kept — a claim needs a citation. The article body is never fetched.
        ->and($closure->url)->toBe('https://www.nicematin.com/vie-locale/promenade-fermee-123')
        ->and($closure->attribution)->toBe('Nice-Matin');

    expect($alerts[1]->kind)->toBe(LocalAlertKind::Strike);
});

it('pins an alert to a real place, and drops it when it cannot', function () {
    $region = DB::table('derived_regions')->insertGetId([
        'id' => (string) Str::uuid7(),
        'key' => 'r5-test-nice',
        'name' => 'Nice',
        'south' => 43.68, 'west' => 7.24, 'north' => 43.72, 'east' => 7.30,
        'locale' => 'fr',
        'requested_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A place the alert names.
    Place::factory()->create([
        'name' => 'Promenade des Anglais',
        'location' => DB::raw("ST_GeogFromText('POINT(7.26 43.69)')"),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(7.26, 43.69), 8)::text AS c')->c,
    ]);

    $named = new LocalAlert(
        'Promenade des Anglais fermée pour travaux', null,
        'https://nicematin.com/a', LocalAlertKind::Closure, 'news_local', 'Nice-Matin',
        CarbonImmutable::now(),
    );

    // ...and one about a street we have never heard of.
    $unknown = new LocalAlert(
        'Rue de Nulle Part barrée', null,
        'https://nicematin.com/b', LocalAlertKind::Closure, 'news_local', 'Nice-Matin',
        CarbonImmutable::now(),
    );

    $ids = app(MaterializeAlertOpportunities::class)([$named, $unknown], 'r5-test-nice');

    // The located one became an opportunity. The unlocatable one was DROPPED — an alert
    // pinned to the wrong place is worse than a missing one.
    expect($ids)->toHaveCount(1);

    $opportunity = Opportunity::query()->find($ids[0]);
    expect($opportunity->kind)->toBe(OpportunityKind::Ephemeral)
        ->and($opportunity->title)->toContain('Promenade des Anglais')
        // It expires — a lifted closure is not a permanent fact about a place.
        ->and($opportunity->expires_at)->not->toBeNull();

    // The evidence is a citation: a headline and a link, filed as CC-BY-SA in the evidence
    // store, never in the world model.
    $evidence = DB::table('opportunity_evidence')->where('opportunity_id', $opportunity->id)->first();
    expect($evidence->excerpt)->toContain('Promenade des Anglais')
        ->and($evidence->url)->toBe('https://nicematin.com/a')
        ->and($evidence->license)->toBe(SourceLicense::CcBySa->value);
});

it('refreshes the same closure instead of spawning a second warning', function () {
    DB::table('derived_regions')->insert([
        'id' => (string) Str::uuid7(), 'key' => 'r5-test-nice', 'name' => 'Nice',
        'south' => 43.68, 'west' => 7.24, 'north' => 43.72, 'east' => 7.30, 'locale' => 'fr',
        'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    Place::factory()->create([
        'name' => 'Promenade des Anglais',
        'location' => DB::raw("ST_GeogFromText('POINT(7.26 43.69)')"),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(7.26, 43.69), 8)::text AS c')->c,
    ]);

    $alert = fn (): LocalAlert => new LocalAlert(
        'Promenade des Anglais fermée pour travaux', null,
        'https://nicematin.com/a', LocalAlertKind::Closure, 'news_local', 'Nice-Matin', CarbonImmutable::now(),
    );

    $materialize = app(MaterializeAlertOpportunities::class);

    // Same closure, read from the same feed on two consecutive polls.
    $materialize([$alert()], 'r5-test-nice');
    $materialize([$alert()], 'r5-test-nice');

    // One warning, refreshed — not two. The dedupe key is the source URL.
    expect(Opportunity::query()->where('kind', OpportunityKind::Ephemeral)->count())->toBe(1)
        ->and(DB::table('opportunity_evidence')->count())->toBe(1);
});
