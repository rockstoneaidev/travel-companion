<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Data\LocalAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads local news/alert feeds and keeps only the disruptions (E39; DATA-SOURCES §3, §9).
 *
 * ## The licensing line, drawn in code
 *
 * DATA-SOURCES §3 is blunt about local newspapers: *"full text frequently paywalled —
 * respect it; extract claims + citation, not article text."* This reader is where "respect
 * it" stops being a sentence and becomes behaviour. It takes the HEADLINE and, at most, the
 * feed's own `<description>` — which the publisher put in a public RSS feed precisely to be
 * syndicated — and it takes the LINK. It never fetches the article. It never stores the
 * body. What survives is a claim and a citation, which is exactly what the licence allows
 * and exactly what a traveller needs: *the coast road is closed, says Nice-Matin, here.*
 *
 * ## Parsing is pure; fetching is not
 *
 * `parse()` takes a string of feed XML and returns alerts, so the classification and the
 * licence discipline are unit-tested against recorded fixtures with no network. `fetch()`
 * is the thin I/O rind around it. Same split as every source adapter (conventions/09).
 */
final class NewsFeedReader
{
    private const HTTP_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly AlertClassifier $classifier,
    ) {}

    /**
     * @return list<LocalAlert>
     */
    public function fetch(string $feedUrl, string $sourceKey, string $attribution, string $locale): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => config('app.name').' (+'.config('app.url').')'])
                ->get($feedUrl);
        } catch (Throwable) {
            return [];   // a feed having a bad minute is honest degradation, never an outage
        }

        if (! $response->successful()) {
            return [];
        }

        return $this->parse($response->body(), $sourceKey, $attribution, $locale);
    }

    /**
     * Feed XML → the disruptions in it, classified. Everything that is not a disruption is
     * dropped here — most local news is not a travel alert, and this keeps none of it.
     *
     * @return list<LocalAlert>
     */
    public function parse(string $xml, string $sourceKey, string $attribution, string $locale): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml);
        } catch (Throwable) {
            $feed = false;
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($feed === false) {
            return [];
        }

        // RSS puts items under channel/item; Atom uses top-level entry. Handle both.
        $items = $feed->channel->item ?? $feed->entry ?? [];
        $alerts = [];

        foreach ($items as $item) {
            $title = trim((string) ($item->title ?? ''));
            $summary = trim((string) ($item->description ?? $item->summary ?? ''));

            if ($title === '') {
                continue;
            }

            $kind = $this->classifier->classify($title.' '.$summary, $locale);

            if ($kind === null) {
                continue;   // not a disruption — nothing a traveller needs to be told
            }

            $alerts[] = new LocalAlert(
                title: $title,
                summary: $summary !== '' ? $summary : null,
                url: $this->link($item),
                kind: $kind,
                sourceKey: $sourceKey,
                attribution: $attribution,
                publishedAt: $this->publishedAt($item),
            );
        }

        return $alerts;
    }

    private function link(\SimpleXMLElement $item): string
    {
        // RSS: <link>text</link>. Atom: <link href="..."/>.
        $link = (string) ($item->link ?? '');

        if ($link === '' && isset($item->link['href'])) {
            $link = (string) $item->link['href'];
        }

        return $link;
    }

    private function publishedAt(\SimpleXMLElement $item): ?CarbonImmutable
    {
        $raw = (string) ($item->pubDate ?? $item->published ?? $item->updated ?? '');

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
