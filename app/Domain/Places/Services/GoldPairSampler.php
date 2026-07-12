<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Data\ResolvableItem;

/**
 * Builds the entity-resolution gold set (ENTITY-RESOLUTION §6).
 *
 * The thresholds (auto_merge 0.82 / review 0.60) were chosen, never measured.
 * Measuring them needs labeled pairs — and the labels must not come from the
 * same signals the scorer uses, or the report just marks its own homework.
 *
 * So labels come from three places, in descending order of trust:
 *
 *   1. `auto:explicit`  — the two items share a Wikidata QID or a Wikipedia
 *      article. A human asserted that identity, independently of any name or
 *      distance heuristic. These are the positives, and they are the reason
 *      this report is worth anything.
 *   2. `auto:disjoint`  — different type domain AND far apart. Near-certain
 *      negatives. Cheap, and honestly a bit circular (distance is a signal),
 *      so they are only used to populate the easy negative mass.
 *   3. `human`          — everything whose score lands near a band edge. This
 *      is where the thresholds actually live, so this is where a person has to
 *      look. The sampler surfaces them; it never guesses them.
 */
final class GoldPairSampler
{
    public function __construct(
        private readonly MatchScorer $scorer,
        private readonly ResolvableItems $items,
    ) {}

    /**
     * @return array{pairs: list<array<string, mixed>>, needs_human: list<array<string, mixed>>}
     */
    public function sample(float $south, float $west, float $north, float $east, int $negativeLimit = 400, int $humanLimit = 60): array
    {
        $items = $this->items->inBoundingBox($south, $west, $north, $east);

        // Positives first, and never capped — a shared explicit identifier is
        // rare and is the only label here a heuristic did not produce. Found by
        // an exact join rather than by scanning: tile-blocking would silently
        // drop every pair whose two sources land either side of a cell edge,
        // which is exactly the pairs a resolver exists to catch.
        $pairs = [];
        foreach ($this->explicitPairs($items) as [$a, $b, $via]) {
            $pairs[] = $this->row($a, $b, 'match', "auto:explicit:{$via}");
        }

        $seen = [];
        foreach ($pairs as $pair) {
            $seen[self::pairKey($pair)] = true;
        }

        $needsHuman = [];
        $negatives = 0;

        foreach ($this->candidatePairs($items) as [$a, $b]) {
            if ($negatives >= $negativeLimit && count($needsHuman) >= $humanLimit) {
                break;
            }

            $ca = ResolutionCandidate::fromPayload($a->payload);
            $cb = ResolutionCandidate::fromPayload($b->payload);
            $distance = (int) round($this->scorer->distanceMeters($ca, $cb));
            $score = $this->scorer->score($ca, $cb)['score'];

            $row = $this->row($a, $b, null, null, $distance, $score);

            if (isset($seen[self::pairKey($row)])) {
                continue;   // already a known positive
            }

            if ($this->isDisjoint($a, $b, $distance)) {
                if ($negatives < $negativeLimit) {
                    $pairs[] = [...$row, 'label' => 'distinct', 'labeled_by' => 'auto:disjoint'];
                    $negatives++;
                }

                continue;
            }

            // Near or above the review edge — where the thresholds actually live.
            if ($this->isAmbiguous($score) && count($needsHuman) < $humanLimit) {
                $needsHuman[] = $row;
            }
        }

        return ['pairs' => $pairs, 'needs_human' => $needsHuman];
    }

    /** @return array<string, mixed> */
    private function row(ResolvableItem $a, ResolvableItem $b, ?string $label, ?string $by, ?int $distance = null, ?float $score = null): array
    {
        if ($distance === null || $score === null) {
            $ca = ResolutionCandidate::fromPayload($a->payload);
            $cb = ResolutionCandidate::fromPayload($b->payload);
            $distance ??= (int) round($this->scorer->distanceMeters($ca, $cb));
            $score ??= $this->scorer->score($ca, $cb)['score'];
        }

        return [
            'a' => ['source' => $a->source, 'external_id' => $a->externalId, 'name' => $a->payload['name'] ?? null],
            'b' => ['source' => $b->source, 'external_id' => $b->externalId, 'name' => $b->payload['name'] ?? null],
            'distance_m' => $distance,
            'score' => $score,
            'label' => $label,
            'labeled_by' => $by,
        ];
    }

    /** @param array<string, mixed> $pair */
    private static function pairKey(array $pair): string
    {
        $a = "{$pair['a']['source']}:{$pair['a']['external_id']}";
        $b = "{$pair['b']['source']}:{$pair['b']['external_id']}";

        return implode('|', [min($a, $b), max($a, $b)]);
    }

    /**
     * Every cross-source pair sharing an explicit identifier, wherever they sit.
     *
     * @param  list<ResolvableItem>  $items
     * @return iterable<array{0: ResolvableItem, 1: ResolvableItem, 2: string}>
     */
    private function explicitPairs(array $items): iterable
    {
        foreach (['wikidata', 'wikipedia'] as $key) {
            $byId = [];

            foreach ($items as $item) {
                $id = $this->explicitId($item, $key);
                if ($id !== null) {
                    $byId[$id][] = $item;
                }
            }

            foreach ($byId as $group) {
                $count = count($group);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if ($group[$i]->source !== $group[$j]->source) {
                            yield [$group[$i], $group[$j], $key];
                        }
                    }
                }
            }
        }
    }

    /**
     * A different kind of thing, and not on top of each other. Sources do
     * sometimes disagree about the domain of one place (a museum inside a
     * historic building) — but when they do, they agree about *where* it is, to
     * within metres. 100 m of separation makes that reading untenable.
     */
    private function isDisjoint(ResolvableItem $a, ResolvableItem $b, int $distanceM): bool
    {
        $domainA = $a->payload['type_domain'] ?? null;
        $domainB = $b->payload['type_domain'] ?? null;

        return $domainA !== null && $domainB !== null && $domainA !== $domainB && $distanceM > 100;
    }

    /**
     * The DTO already normalises both identifiers — the sitelink in particular,
     * which OSM and Wikidata spell differently. Reimplementing that here would
     * be a second, quietly divergent definition of "same article".
     */
    private function explicitId(ResolvableItem $item, string $key): ?string
    {
        return match ($key) {
            'wikidata' => $item->wikidataQid(),
            'wikipedia' => $item->wikipediaSitelink(),
            default => null,
        };
    }

    /**
     * Everything the resolver would not confidently call distinct.
     *
     * That is deliberately asymmetric. Above the review edge live two things a
     * human must adjudicate: the pairs we would *merge* (a wrong label here is a
     * false merge — corruption) and the pairs we would *queue*. Below it, the
     * resolver is already saying "these are different", and it is right often
     * enough that hand-labelling that mass buys nothing but fatigue.
     */
    private function isAmbiguous(float $score): bool
    {
        return $score >= (float) config('resolver.bands.review') - 0.05;
    }

    /**
     * Only pairs worth asking about: same tile, different source. Cross-source
     * is where resolution actually happens — two rows from one source are that
     * source's own duplicate problem, not ours.
     *
     * @param  list<ResolvableItem>  $items
     * @return iterable<array{0: ResolvableItem, 1: ResolvableItem}>
     */
    private function candidatePairs(array $items): iterable
    {
        $byTile = [];
        foreach ($items as $item) {
            $byTile[$item->h3Index][] = $item;
        }

        foreach ($byTile as $tileItems) {
            $count = count($tileItems);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tileItems[$i]->source !== $tileItems[$j]->source) {
                        yield [$tileItems[$i], $tileItems[$j]];
                    }
                }
            }
        }
    }
}
