<?php

declare(strict_types=1);

use App\Domain\Context\Services\OsmOpeningHours;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| OSM opening_hours, parsed conservatively (E50 cost lever)
|--------------------------------------------------------------------------
|
| The whole point is to answer the EASY cases free and hand the hard ones to Google. A
| wrong "open" is worse than a Google call, so every ambiguity — a boundary, a grammar we
| don't fully parse, an unknown timezone near a close time — must return null.
|
*/

// 2026-07-16 is a Thursday.
function thu(string $hm): CarbonImmutable
{
    return CarbonImmutable::parse("2026-07-16 {$hm}:00");
}

it('is confidently open in the clear middle of opening hours', function () {
    $r = app(OsmOpeningHours::class)->evaluate('Mo-Fr 09:00-18:00', thu('14:00'));
    expect($r?->known)->toBeTrue()->and($r?->openNow)->toBeTrue();
});

it('is confidently closed in the dead of night', function () {
    $r = app(OsmOpeningHours::class)->evaluate('Mo-Fr 09:00-18:00', thu('03:00'));
    expect($r?->known)->toBeTrue()->and($r?->openNow)->toBeFalse();
});

it('is confidently closed on a day it does not open', function () {
    // Thursday, but the place only opens Sat-Sun.
    $r = app(OsmOpeningHours::class)->evaluate('Sa-Su 10:00-16:00', thu('14:00'));
    expect($r?->openNow)->toBeFalse();
});

it('hands the near-boundary case to Google — where the timezone we do not know could flip it', function () {
    // 17:45, closes 18:00. Within the ±2.5h margin the window straddles closing → null.
    expect(app(OsmOpeningHours::class)->evaluate('Mo-Fr 09:00-18:00', thu('17:45')))->toBeNull();
    // And just after opening.
    expect(app(OsmOpeningHours::class)->evaluate('Mo-Fr 09:00-18:00', thu('09:30')))->toBeNull();
});

it('understands 24/7', function () {
    expect(app(OsmOpeningHours::class)->evaluate('24/7', thu('03:00'))?->openNow)->toBeTrue();
});

it('handles a split day and multiple rules', function () {
    $spec = 'Mo-Fr 08:00-12:00,13:00-18:00; Sa 10:00-14:00';
    // Thursday 15:00 — inside the afternoon block, clear of both boundaries.
    expect(app(OsmOpeningHours::class)->evaluate($spec, thu('15:00'))?->openNow)->toBeTrue();
    // Thursday 12:30 — in the lunch gap, but only 30 min from both edges → ambiguous → null.
    expect(app(OsmOpeningHours::class)->evaluate($spec, thu('12:30')))->toBeNull();
});

it('refuses grammar it does not fully understand — a guess would be a lie', function () {
    $oh = app(OsmOpeningHours::class);
    foreach ([
        'Mo-Fr 09:00-18:00; PH off',          // public holidays
        'Mo-Su sunrise-sunset',                // sun events
        'Apr-Sep 09:00-20:00',                 // seasonal
        'Mo 09:00-18:00 "by appointment"',     // comment
        'Mo[1] 09:00-18:00',                   // first Monday of the month
        '22:00-02:00',                         // overnight (crosses midnight)
    ] as $spec) {
        expect($oh->evaluate($spec, thu('14:00')))->toBeNull("expected null for: {$spec}");
    }
});

it('returns null for an absent or empty tag', function () {
    $oh = app(OsmOpeningHours::class);
    expect($oh->evaluate(null, thu('14:00')))->toBeNull()
        ->and($oh->evaluate('', thu('14:00')))->toBeNull();
});
