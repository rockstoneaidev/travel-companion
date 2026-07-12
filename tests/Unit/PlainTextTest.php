<?php

declare(strict_types=1);

use App\Support\PlainText;

/*
|--------------------------------------------------------------------------
| Source markup never reaches a traveller (conventions/10)
|--------------------------------------------------------------------------
|
| A curated claim reached APPROVAL reading "...this lake, which allows
| [[water sports." — a wiki link whose closing brackets were lost to an excerpt
| truncation. It was live, and it would have been read to somebody standing
| next to the lake.
|
*/

it('unwraps a wiki link to the words a person would read', function () {
    expect(PlainText::clean('See the [[Vasa Museum]] nearby.'))->toBe('See the Vasa Museum nearby.')
        ->and(PlainText::clean('A [[Water sports|watersports]] lake.'))->toBe('A watersports lake.');
});

it('survives a link the excerpt limit cut in half — the actual bug', function () {
    // Truncation landed mid-link and orphaned the opening brackets, so the
    // well-formed-link pattern could never match it.
    expect(PlainText::clean('this lake, which allows [[water sports.'))
        ->toBe('this lake, which allows water sports.');
});

it('strips templates, emphasis, HTML and reference markers', function () {
    expect(PlainText::clean('{{Infobox place}}A church.'))->toBe('A church.')
        ->and(PlainText::clean("'''Bold''' and ''italic''."))->toBe('Bold and italic.')
        ->and(PlainText::clean('<p>A <b>church</b>.</p>'))->toBe('A church.')
        ->and(PlainText::clean('A church.[1] Built in 1531.[citation needed]'))->toBe('A church. Built in 1531.');
});

it('decodes the entities a CMS leaves behind', function () {
    expect(PlainText::clean('Costs 130&nbsp;kr &amp; worth it.'))->toBe('Costs 130 kr & worth it.');
});

it('leaves clean prose exactly as it is', function () {
    $clean = 'The 1531 stained glass in the side chapel shows the Wisdom of Solomon.';

    expect(PlainText::clean($clean))->toBe($clean);
});

it('recognises markup so a live row can be found and fixed', function () {
    expect(PlainText::hasMarkup('allows [[water sports.'))->toBeTrue()
        ->and(PlainText::hasMarkup('costs 130&nbsp;kr'))->toBeTrue()
        ->and(PlainText::hasMarkup('<p>hello</p>'))->toBeTrue()
        ->and(PlainText::hasMarkup('A perfectly ordinary sentence.'))->toBeFalse();
});
