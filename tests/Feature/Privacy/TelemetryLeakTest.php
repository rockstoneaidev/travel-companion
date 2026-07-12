<?php

declare(strict_types=1);

use Laravel\Pulse\Recorders;

/*
|--------------------------------------------------------------------------
| Telemetry must not become storage (DPIA §5.1, ROPA §7.2)
|--------------------------------------------------------------------------
|
| Pulse's SlowOutgoingRequests recorder writes the outgoing URL into
| `pulse_entries.key`. One of our outgoing URLs carries a user's precise
| position in its query string — Open-Meteo is a GET with ?latitude&longitude.
|
| So a weather call that ran slow wrote WHERE A PERSON WAS STANDING into a
| diagnostics table that the retention job does not touch and account deletion
| does not clear. And the home zone promises a coordinate inside it is NEVER
| written — "not for thirty days, not for thirty seconds".
|
| The defence is a `groups` rule that strips the query string. This test is
| what stops someone deleting it as clutter, because a commented-out privacy
| control looks exactly like a commented-out example.
|
*/

/** The grouping rules Pulse will apply to a recorded outgoing URL. */
function outgoingUrlGroups(): array
{
    return config('pulse.recorders.'.Recorders\SlowOutgoingRequests::class.'.groups', []);
}

/** Apply them the way Pulse does: first matching pattern wins. */
function groupedKey(string $url): string
{
    foreach (outgoingUrlGroups() as $pattern => $replacement) {
        $grouped = preg_replace($pattern, $replacement, $url);

        if ($grouped !== $url && $grouped !== null) {
            return $grouped;
        }
    }

    return $url;
}

it('never records a user coordinate from the weather URL', function (): void {
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=59.3103&longitude=18.0227&current=temperature_2m';

    $key = groupedKey($url);

    expect($key)->not->toContain('59.3103')
        ->and($key)->not->toContain('18.0227')
        ->and($key)->not->toContain('latitude')
        ->and($key)->toBe('https://api.open-meteo.com/v1/forecast');
});

it('still records enough to tell us which endpoint is slow', function (): void {
    // The recorder has to keep earning its keep: "Open-Meteo is slow" is the whole
    // reason it exists. Stripping the query must not strip the host and path.
    expect(groupedKey('https://api.open-meteo.com/v1/forecast?latitude=1&longitude=2'))
        ->toBe('https://api.open-meteo.com/v1/forecast');
});

it('leaves a URL with no query string alone', function (): void {
    $url = 'https://places.googleapis.com/v1/places/ChIJ123';

    expect(groupedKey($url))->toBe($url);
});

it('strips the query from any future endpoint, not just the one we know about', function (): void {
    // The rule is generic on purpose. The next GET someone adds with a coordinate,
    // an email, or a token in the query string is covered without anyone remembering.
    expect(groupedKey('https://example.test/v1/thing?email=someone@example.com&token=abc'))
        ->toBe('https://example.test/v1/thing');
});
