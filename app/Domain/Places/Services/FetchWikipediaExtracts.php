<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;
use App\Support\Http\Harvest;
use Illuminate\Support\Facades\DB;

/**
 * The narrative layer (DATA-SOURCES §2, Wikipedia — P1, and it was never built).
 *
 * ===========================================================================
 *  WHY THIS EXISTS, AND WHY IT WAS URGENT
 * ===========================================================================
 *
 * The curation selector only drafts places that have EVIDENCE — a sentence somebody
 * else wrote that we may quote. It accepted exactly two sources: `datatourisme` and
 * `merimee`. Both are FRENCH.
 *
 * So Stockholm — the home region, the test region, the place the founder actually
 * lives — was structurally incapable of producing a single curation candidate, no
 * matter how many times the world model was rebuilt. Its 11,000 places had OSM tags
 * (which are not prose) and Wikidata `p31` type codes (which are not prose either).
 * Zero of 5,788 Wikidata items carried a description. The curation queue for
 * Stockholm was empty, and re-running the ingest would have kept it empty forever.
 *
 * DATA-SOURCES said so all along: "OSM has no stories", and it lists Wikipedia as a
 * P1 source, the narrative layer — history, architecture, legends. It was simply
 * never written.
 *
 * The concordance was ALREADY there: 4,326 places carry a `wikipedia` external id,
 * harvested from OSM's `wikipedia=lang:Title` tags. We knew which article described
 * each place. We had just never gone and read it.
 *
 * ===========================================================================
 *  LICENSING — read before touching this (conventions/09, ODBL-REVIEW §6)
 * ===========================================================================
 *
 * Wikipedia is CC BY-SA. That makes it EVIDENCE-ONLY: it may live in the evidence
 * store, with its license and attribution on the row, and it may be QUOTED by a
 * curated claim that carries the attribution. It may NEVER be merged into
 * `places_core` — mixing CC BY-SA into an ODbL derivative database is exactly the
 * kind of contamination ODBL-REVIEW exists to prevent.
 *
 * So this writes `source_items` with storage_policy = evidence_only, keyed to places
 * through the existing concordance. It creates no places and modifies none.
 */
final class FetchWikipediaExtracts
{
    public const SOURCE = 'wikipedia';

    private const USER_AGENT = 'TravelCompanion-evidence/1.0 (rockstoneaidev@gmail.com)';

    /** The API takes up to 20 titles per call, and one language per call. */
    private const TITLES_PER_CALL = 20;

    private const ATTRIBUTION = 'Wikipedia contributors, CC BY-SA 4.0';

    public function __construct(private readonly Harvest $harvest) {}

    /**
     * Fetch intro extracts for up to $limit Wikipedia-linked places that have none.
     *
     * `throttled` is the difference between "these places have no article" and "we
     * were not allowed to ask". Both used to look identical from the outside, which
     * is how Stockholm ended up with 4,326 known articles and 20 stored ones.
     *
     * @return array{candidates: int, extracts: int, throttled: bool}
     */
    public function fetchBatch(int $limit = 60): array
    {
        $rows = DB::select(
            'SELECT psi.place_id, psi.external_id
             FROM place_source_ids psi
             WHERE psi.source = ?
               AND NOT EXISTS (
                   SELECT 1 FROM source_items si
                   WHERE si.source = ? AND si.external_id = psi.external_id
               )
             ORDER BY psi.place_id
             LIMIT ?',
            [self::SOURCE, self::SOURCE, $limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'extracts' => 0, 'throttled' => false];
        }

        // `sv:Storkyrkan` — the language is part of the identifier, and each language
        // is a different Wikipedia with a different hostname.
        $byLanguage = [];
        foreach ($rows as $row) {
            [$language, $title] = array_pad(explode(':', (string) $row->external_id, 2), 2, null);

            if ($title === null || $title === '') {
                continue;
            }

            $byLanguage[$language][str_replace('_', ' ', $title)] = (string) $row->external_id;
        }

        $stored = 0;
        $throttled = false;

        foreach ($byLanguage as $language => $titles) {
            foreach (array_chunk(array_keys($titles), self::TITLES_PER_CALL) as $chunk) {
                $extracts = $this->extracts($language, $chunk);

                // null = we never got an answer. Do NOT treat that as "no article":
                // the rows stay candidates, and a later run picks them up again.
                if ($extracts === null) {
                    $throttled = true;

                    break 2;   // stop asking; we are already being told to slow down
                }

                foreach ($extracts as $title => $extract) {
                    $externalId = $titles[$title] ?? null;

                    if ($externalId === null || $extract === '') {
                        continue;
                    }

                    $this->store($externalId, $language, $title, $extract);
                    $stored++;
                }
            }
        }

        return ['candidates' => count($rows), 'extracts' => $stored, 'throttled' => $throttled];
    }

    /**
     * The intro paragraph, plain text.
     *
     * `exintro` + `explaintext` because we want the lede a human wrote, not the
     * whole article and not wiki markup. The LLM drafts FROM this; it is never a
     * source of facts itself (conventions/10).
     *
     * A 429 IS NOT AN ANSWER. The first version of this returned `[]` on any failed
     * response, which meant "Wikipedia told us to slow down" and "this place has no
     * article" were the same value. They are not remotely the same fact: one is
     * recoverable by waiting, the other never is. Conflating them silently emptied
     * Stockholm's evidence — 4,326 linked articles, 20 stored — and left the curation
     * queue looking like a region with nothing written about it.
     *
     * The backoff now lives in {@see Harvest} (the ingest lane's policy: exponential,
     * jittered, honours Retry-After). What stays here is the SEMANTIC half, which is
     * the half that actually mattered: null means "ask again later", an empty array
     * means "asked, and these articles do not exist".
     *
     * @param  list<string>  $titles
     * @return array<string, string>|null title => extract, or null if we never got an answer
     */
    private function extracts(string $language, array $titles): ?array
    {
        $result = $this->harvest->get(
            "https://{$language}.wikipedia.org/w/api.php",
            [
                'action' => 'query',
                'format' => 'json',
                'prop' => 'extracts',
                'exintro' => 1,
                'explaintext' => 1,
                'redirects' => 1,
                // The extracts API returns ONE extract per request unless told
                // otherwise. Without this, 19 of every 20 titles came back with no
                // `extract` key and looked — again — like articles that don't exist.
                'exlimit' => self::TITLES_PER_CALL,
                'titles' => implode('|', $titles),
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 20,
        );

        if ($result->unknown()) {
            return null;   // never got an answer — NOT "no article"
        }

        $response = $result->response;

        if ($response === null) {
            return null;
        }

        $out = [];

        foreach ($response->json('query.pages') ?? [] as $page) {
            if (! isset($page['title'], $page['extract'])) {
                continue;   // a missing article is a fact about the world, not an error
            }

            $out[$page['title']] = trim((string) $page['extract']);
        }

        // The API resolves redirects, so the title we asked for may not be the title
        // we got back. Map the answer home.
        foreach ($response->json('query.redirects') ?? [] as $redirect) {
            if (isset($out[$redirect['to']])) {
                $out[$redirect['from']] = $out[$redirect['to']];
            }
        }

        return $out;
    }

    private function store(string $externalId, string $language, string $title, string $extract): void
    {
        DB::statement(
            "INSERT INTO source_items
                (id, source, external_id, license, storage_policy, credibility_tier, payload,
                 h3_index, source_adapter_version, attribution, retrieved_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?::jsonb, NULL, 'v1', ?, now(), now(), now())
             ON CONFLICT (source, external_id) WHERE external_id IS NOT NULL
             DO UPDATE SET payload = EXCLUDED.payload, retrieved_at = EXCLUDED.retrieved_at, updated_at = now()",
            [
                self::SOURCE,
                $externalId,
                SourceLicense::CcBySa->value,
                // EVIDENCE-ONLY. CC BY-SA may be quoted with attribution; it may never
                // be merged into places_core (conventions/09, ODBL-REVIEW §6).
                StoragePolicy::EvidenceOnly->value,
                CredibilityTier::Reference->value,
                json_encode([
                    'name' => $title,
                    'language' => $language,
                    'source_tags' => [
                        // Same key the curation selector reads from DATAtourisme, so the
                        // evidence rule stays one rule rather than three.
                        'description' => $extract,
                        'url' => "https://{$language}.wikipedia.org/wiki/".rawurlencode(str_replace(' ', '_', $title)),
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                self::ATTRIBUTION,
            ],
        );
    }
}
