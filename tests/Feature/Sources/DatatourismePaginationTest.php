<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\DatatourismeAdapter;
use App\Domain\Sources\Data\ScoutRequest;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The cursor is followed, never believed
|--------------------------------------------------------------------------
|
| `meta.next` keeps handing out a URL AFTER the last page of the bounding box.
| Following it walks straight out of the region: the Paris ingest ran past its
| 215th page and carried on through the national catalogue, writing POIs from
| the Alps, the Aude and Alsace into the database as though they were Paris —
| and then threw `exceeds 500 pages`, because of course it never ran out.
|
| The result was the worst of both worlds. Paris got NO DATAtourisme layer at
| all (the throw failed the source, and DATA-SOURCES calls this "the single
| highest-value source for a French launch"), and the database collected a
| thousand rows of France at random.
|
| The API was never wrong. It says plainly that the Paris box holds 4,284 POIs
| across 215 pages. We just never read the answer.
|
*/

function parisRequest(): ScoutRequest
{
    return new ScoutRequest(
        regionKey: 'paris', south: 48.815, west: 2.224, north: 48.902, east: 2.47, locale: 'fr',
    );
}

beforeEach(function () {
    config()->set('services.datatourisme.key', 'test-key');
});

it('stops at the last page of the bbox, however many more the cursor offers', function () {
    $page = fn (int $n): array => [
        'objects' => [['uuid' => "poi-{$n}", 'type' => [], 'label' => ['@fr' => "POI {$n}"]]],
        'meta' => [
            'total' => 3,
            'page' => $n,
            'total_pages' => 3,
            // The cursor NEVER stops. That is the real API's behaviour, and it is the bug.
            'next' => 'https://api.datatourisme.fr/v1/catalog?cursor=always-another',
        ],
    ];

    Http::fake([
        'api.datatourisme.fr/*' => Http::sequence()
            ->push($page(1))
            ->push($page(2))
            ->push($page(3))
            // A fourth request would be a page from OUTSIDE Paris. If the adapter makes
            // one, the sequence runs dry and this test fails — which is the failure we
            // want, rather than a silent walk across France.
            ->whenEmpty(Http::response(['objects' => [['uuid' => 'somewhere-in-the-alps']], 'meta' => []])),
    ]);

    $pages = iterator_to_array(app(DatatourismeAdapter::class)->pages(parisRequest()), false);

    // Three, because the API said the box holds three. Not four, and not five hundred.
    expect($pages)->toHaveCount(3);

    Http::assertSentCount(3);
});

it('stops when a page comes back empty, even if the cursor is still talking', function () {
    Http::fake([
        'api.datatourisme.fr/*' => Http::response([
            'objects' => [],
            'meta' => ['next' => 'https://api.datatourisme.fr/v1/catalog?cursor=more', 'total_pages' => 99],
        ]),
    ]);

    // An empty page means the bbox is exhausted. Nothing beyond it can belong to the
    // region, whatever the cursor claims.
    expect(iterator_to_array(app(DatatourismeAdapter::class)->pages(parisRequest()), false))->toBe([]);

    Http::assertSentCount(1);
});

it('walks every page the bbox really has', function () {
    // The honest half of the same rule: bounded by the API's own count, not short of it.
    // A truncated region that reports success is the lie the whole pipeline builds on.
    $sequence = Http::sequence();

    for ($n = 1; $n <= 5; $n++) {
        $sequence->push([
            'objects' => [['uuid' => "poi-{$n}", 'type' => [], 'label' => ['@fr' => "POI {$n}"]]],
            'meta' => ['total_pages' => 5, 'next' => 'https://api.datatourisme.fr/v1/catalog?cursor=next'],
        ]);
    }

    Http::fake(['api.datatourisme.fr/*' => $sequence]);

    expect(iterator_to_array(app(DatatourismeAdapter::class)->pages(parisRequest()), false))->toHaveCount(5);

    Http::assertSentCount(5);
});
