<?php

declare(strict_types=1);

use App\Domain\Curation\Actions\ReviewCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Services\ClaimGuard;
use App\Domain\Places\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Cut the sentence, keep the place
|--------------------------------------------------------------------------
|
| ClaimGuard could only ever say NO — and saying no threw the place away along
| with the sentence. The review queue filled with good claims about real places,
| each carrying one clause we were never allowed to say ("a coffee is €1", "open
| Wednesdays through Sundays"), and the only button offered was Reject. Losing a
| bistro nobody else will tell you about, over a price.
|
| Worse: the guard's own docblock promised "a draft that names a price does not
| reach a traveller no matter how well the verifier likes its prose" — and that
| was only true of the VERIFIER's path. ReviewCuratedItem::approve() never
| consulted the guard at all. The enforcement had a door in it, and the door was
| the button the reviewer is invited to press on every single item.
|
| So the clause is CUT and the claim stands. Deleting is not rewriting: no model
| is asked to try again, because a rewrite is where it would start inventing.
|
*/

function perishableItem(string $claim): CuratedItem
{
    $place = Place::factory()->create(['source_tags' => ['osm' => []]]);

    return CuratedItem::query()->create([
        'place_id' => $place->id,
        'region_slug' => 'paris',
        'title' => 'Les Pères Populaires',
        'claim' => $claim,
        'facets' => ['food_drink'],
        'evidence' => [[
            'url' => 'https://example.test',
            'source_type' => 'datatourisme',
            'license' => 'licence_ouverte',
            'attribution' => 'DATAtourisme',
            'excerpt' => 'Un bistrot original avec un décor surprenant.',
        ]],
        'status' => CurationStatus::InReview,
        'authored_by' => 'llm',
    ]);
}

it('cuts the perishable sentence and keeps the rest of the claim', function () {
    // The real one, from the review queue.
    $claim = 'Decorated with salvaged sofas and school chairs, this relaxed bistro serves market-fresh lunch dishes and drinks. A coffee costs €1.';

    $trimmed = app(ClaimGuard::class)->trimPerishable($claim);

    expect($trimmed)->toBe('Decorated with salvaged sofas and school chairs, this relaxed bistro serves market-fresh lunch dishes and drinks.')
        ->and(app(ClaimGuard::class)->isPerishable($trimmed))->toBeFalse();
});

it('drops a claim that was only ever a price', function () {
    // There is no bistro underneath this. Nothing to keep.
    expect(app(ClaimGuard::class)->trimPerishable('Admission is €12.'))->toBe('');
});

it('leaves a claim with nothing perishable in it exactly as it was', function () {
    $claim = 'Sculpted by Johan Peter Molin and unveiled in 1868, this statue depicts King Charles XII holding a sword.';

    expect(app(ClaimGuard::class)->trimPerishable($claim))->toBe($claim);
});

it('will not let a human approve a price into the product', function () {
    // The door in the enforcement: approve() never asked the guard. A reviewer clicking
    // Approve — which is what a reviewer does — published the price.
    $item = perishableItem('This museum shows playing cards from the 15th century. It is open Wednesdays through Sundays and an admission fee is charged.');

    $reviewer = User::factory()->create();

    app(ReviewCuratedItem::class)->approve($item, $reviewer->id);

    $item->refresh();

    expect($item->status)->toBe(CurationStatus::Approved)
        // The place survives...
        ->and($item->claim)->toBe('This museum shows playing cards from the 15th century.')
        // ...the perishable sentence does not...
        ->and(app(ClaimGuard::class)->isPerishable((string) $item->claim))->toBeFalse()
        // ...and the text is no longer purely the model's, so it says so.
        ->and($item->authored_by)->toBe('human');
});

it('refuses an approval when nothing survives the cut', function () {
    $item = perishableItem('Entry is €9.');
    $reviewer = User::factory()->create();

    expect(fn () => app(ReviewCuratedItem::class)->approve($item, $reviewer->id))
        ->toThrow(InvalidArgumentException::class);

    // Still in review. It was never a claim; a reviewer must reject it deliberately.
    expect($item->refresh()->status)->toBe(CurationStatus::InReview);
});

it('leaves an untouched claim authored by the model', function () {
    // A trim is an edit, and only an edit changes the authorship. A claim we did not
    // touch is still the model's work, and the record must not pretend otherwise.
    $item = perishableItem('Sculpted by Johan Peter Molin and unveiled in 1868, this statue depicts King Charles XII.');
    $reviewer = User::factory()->create();

    app(ReviewCuratedItem::class)->approve($item, $reviewer->id);

    expect($item->refresh()->authored_by)->toBe('llm');
});
