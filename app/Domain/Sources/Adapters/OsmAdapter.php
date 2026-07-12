<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Places\Taxonomy\OsmTagMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use App\Domain\Sources\Exceptions\OverpassRateLimited;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OpenStreetMap adapter (DATA-SOURCES §2): the long-tail layer — viewpoints,
 * ruins, fountains, city gates — the things Google doesn't have.
 *
 * Fetch strategy: Overpass bbox query with adaptive subdivision (see search()).
 * DATA-SOURCES §2 sanctions Overpass for the bounded city-scale regions of
 * Phase 1 — Stockholm and the seven France-corridor cities. When a region stops
 * being city-scale (a country, or continuous re-ingest), the documented
 * production path is a Geofabrik extract via osm2pgsql: same normalize(),
 * different search() plumbing.
 */
final class OsmAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'osm';

    public const VERSION = 'v1';

    /**
     * Endpoints, in preference order — because a single hard-coded host is a
     * single point of failure, and it failed.
     *
     * lz4 is still first: it answers fastest and compresses. But it is not always
     * *reachable* — from the local Docker network it refuses the connection in
     * 18 ms while the main instance answers fine, and a region does not deserve to
     * die of that. Kumi is last: it stalls rather than refusing, so it is only
     * worth trying when both of the others are gone.
     */
    private const OVERPASS_ENDPOINTS = [
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    // 4 quadrants × 4^3 = up to 256 sub-boxes for the worst region. Paris needs
    // one or two splits; the bound exists so a dead endpoint cannot turn into a
    // fork bomb of politeness sleeps.
    private const MAX_SPLIT_DEPTH = 3;

    /**
     * The wall clock, which is what actually killed Nice.
     *
     * MAX_SPLIT_DEPTH bounds *breadth*, not *time*, and the two are not the same
     * thing: every box a split creates re-pays the full HTTP budget, so retrying
     * and splitting multiply. A region that 504s all the way down visits up to
     * 4 + 16 + 64 + 256 boxes, and at ~150 s each that is hours — no fixed job
     * timeout survives it, which is why raising `$timeout` is not the fix.
     *
     * So the recursion is bounded in seconds as well as in depth. The budget sits
     * inside BuildRegionWorldModelJob's timeout with room for normalize() and the
     * upsert afterwards: we would rather hand back a partial region and say so
     * (conventions/09) than be killed mid-request and hand back nothing.
     *
     * Sized for ONE BOX, not a whole region: BuildRegionWorldModelJob now cuts the
     * region into a grid and gives each cell its own job (IngestRegion::boxes()), so
     * this budget covers a few square kilometres rather than 584 of them. That is
     * what lets the job timeout come DOWN to 600 s — comfortably clear of the
     * queue's retry_after, which is the ceiling that killed Stockholm.
     * Enforced against the job's timeout by tests/Feature/QueueConfigTest.php.
     */
    public const BUDGET_SECONDS = 300;

    /**
     * Just above the `[timeout:120]` we ask Overpass for, so the server's own
     * timeout fires first and we get a 504 to split on rather than a client abort
     * we can't attribute. Was 180 s with `->retry(2, 10000)` — 370 s for a single
     * doomed box, and the retry re-asked the *identical* question that had just
     * timed out. Overpass timed out because the question was too big; the answer
     * is a smaller question, not the same one again. The splitter IS the retry.
     */
    public const HTTP_TIMEOUT_SECONDS = 150;

    /** Public Overpass rate-limits bursts. The ingest lane is serial anyway. */
    private const POLITENESS_SECONDS = 2;

    /** How long to wait when Overpass says 429 and does not tell us how long. */
    private const DEFAULT_BACKOFF_SECONDS = 45;

    /** ...and a ceiling, so one hostile Retry-After header cannot eat the whole budget. */
    private const MAX_BACKOFF_SECONDS = 90;

    public function supports(ScoutRequest $request): bool
    {
        return true; // the base layer supports every region
    }

    /**
     * Overpass times out as a function of how much it has to answer, so the
     * answer to a timeout is a smaller question.
     *
     * A fixed 4-quadrant split was enough for one Stockholm-sized region and is
     * NOT enough for the France corridor: Paris alone returns 34k elements, and
     * running seven cities back to back had public Overpass returning 504 on
     * whole quadrants — silently costing Nantes its entire OSM layer. So the
     * split is now adaptive: a quadrant that fails is quartered and retried,
     * down to a depth bound. Ways on a seam appear in both halves — dedupe on
     * type/id.
     *
     * (This is the shape that survives at city scale. At country scale the
     * documented path is still Geofabrik → osm2pgsql — DATA-SOURCES §2.)
     */
    public function search(ScoutRequest $request): array
    {
        $elements = [];
        $state = ['failed' => 0, 'skipped' => 0, 'requests' => 0];
        $deadline = CarbonImmutable::now()->addSeconds(self::BUDGET_SECONDS);

        /*
         * Ask for the box AS IT IS. Split only if Overpass says no.
         *
         * This used to quarter every request BEFORE asking anything — sensible when a
         * "box" was a whole 584 km² region, and pure waste now that the region arrives
         * pre-gridded (IngestRegion::boxes()). It quadrupled the query count: Stockholm's
         * 45 boxes became 180 Overpass round-trips, and a box holding eight places took
         * 280 seconds to fetch them.
         *
         * The splitter is a REMEDY, not a strategy. It exists for the box that comes back
         * 504 — and it still fans out exactly as before when one does.
         */
        $this->collect($request, 0, $elements, $state, $deadline);

        if ($state['skipped'] > 0) {
            // Silent truncation reads as "covered everything". Say what was dropped.
            Log::warning("osm ingest {$request->regionKey}: ran out of time", [
                'boxes_skipped' => $state['skipped'],
                'boxes_failed' => $state['failed'],
                'elements' => count($elements),
                'budget_seconds' => self::BUDGET_SECONDS,
            ]);
        }

        // Something is better than nothing (coverage honesty, conventions/09) —
        // but *nothing* must not be mistaken for "this region has no places".
        if ($elements === [] && ($state['failed'] > 0 || $state['skipped'] > 0)) {
            throw new RuntimeException(sprintf(
                'Overpass returned nothing for region "%s" (%d boxes failed, %d never attempted).',
                $request->regionKey,
                $state['failed'],
                $state['skipped'],
            ));
        }

        return array_values($elements);
    }

    /**
     * @param  array<string, array<string, mixed>>  $elements
     * @param  array{failed: int, skipped: int, requests: int}  $state
     */
    private function collect(ScoutRequest $box, int $depth, array &$elements, array &$state, CarbonImmutable $deadline): void
    {
        // Never *start* a request that could run past the deadline. Checking after
        // the fact would be checking too late: the job dies inside curl_exec, and
        // everything fetched so far dies with it, unwritten.
        if (CarbonImmutable::now()->addSeconds(self::HTTP_TIMEOUT_SECONDS)->greaterThan($deadline)) {
            $state['skipped']++;

            return;
        }

        if ($state['requests'] > 0 && ! app()->runningUnitTests()) {
            sleep(self::POLITENESS_SECONDS);   // between requests, not before the first
        }

        $state['requests']++;

        try {
            $response = $this->ask($box);
        } catch (OverpassRateLimited $e) {
            /*
             * 429 — the server said SLOW DOWN, and splitting would answer by asking
             * four times as often. That is a feedback loop straight into a ban, and it
             * is exactly what this adapter used to do, because every HTTP error looked
             * like "the question was too big".
             *
             * It is not too big. It is too OFTEN. There is no smaller question that
             * helps, so we back off (in ask()) and, if it persists, give the box up.
             * Overpass is a volunteer service we do not pay for; being asked to wait is
             * a reasonable thing for it to do, and hammering through it is not a
             * reasonable thing for us to do.
             */
            $state['failed']++;

            Log::warning("osm ingest {$box->regionKey}: rate limited, box abandoned", [
                'south' => $box->south, 'west' => $box->west,
            ]);

            return;
        } catch (ConnectionException $e) {
            // We could not REACH any endpoint. That is not a question that is too
            // big, so a smaller question cannot fix it — splitting here just asks
            // an unreachable host four more times. Nice failed 188 boxes this way
            // in 8 minutes, every one of them refused in under 20 ms.
            $state['failed']++;

            return;
        } catch (RequestException $e) {
            // The server ANSWERED, and the answer was "no" — a 504 means Overpass
            // timed out working, i.e. the question was too big. THAT is what a
            // smaller question fixes.
            //
            // Only these two split. The catch used to be `Throwable`, which meant
            // the framework's own TimeoutExceededException — the signal that the
            // job is out of time — was answered by issuing *more* HTTP requests,
            // and a plain bug in this method looked like a bad day at Overpass.
            if ($depth >= self::MAX_SPLIT_DEPTH) {
                $state['failed']++;   // give up on this patch, keep the rest of the region

                return;
            }

            foreach ($this->quadrants($box) as $quadrant) {
                $this->collect($quadrant, $depth + 1, $elements, $state, $deadline);
            }

            return;
        }

        foreach ($response->json('elements') ?? [] as $element) {
            $elements[$element['type'].'/'.$element['id']] = $element;
        }
    }

    /**
     * One box, asked of the first endpoint that will take the question.
     *
     * Failing over costs nothing when the failure is a refused connection (it is
     * immediate), which is exactly the case it exists for. A server that answers
     * with an error is NOT failed over to the next host — every public Overpass
     * runs the same software and would give the same answer; the box needs to get
     * smaller, not to move.
     *
     * @throws ConnectionException no endpoint was reachable
     * @throws RequestException an endpoint answered with an error
     */
    private function ask(ScoutRequest $box): Response
    {
        $unreachable = null;
        $rateLimited = false;

        foreach (self::OVERPASS_ENDPOINTS as $endpoint) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                        ->withHeaders(['User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)'])
                        ->asForm()
                        ->post($endpoint, ['data' => $this->overpassQuery($box)]);
                } catch (ConnectionException $e) {
                    $unreachable = $e;

                    continue 2;   // this host is not answering at all — try the next one
                }

                if ($response->status() !== 429) {
                    // 5xx here throws RequestException, and the caller splits the box —
                    // which is the right remedy for "your question was too big".
                    return $response->throw();
                }

                $rateLimited = true;

                // Asked to wait. Honour Retry-After when they send one; they know their
                // own load better than our guess does.
                $wait = min(self::MAX_BACKOFF_SECONDS, max(1, (int) $response->header('Retry-After') ?: self::DEFAULT_BACKOFF_SECONDS));

                Log::info("osm ingest {$box->regionKey}: rate limited, waiting {$wait}s", ['endpoint' => $endpoint]);

                if ($attempt === 1 && ! app()->runningUnitTests()) {
                    sleep($wait);
                }
            }
        }

        // Every endpoint either refused the connection or told us to wait.
        if ($rateLimited) {
            throw new OverpassRateLimited('Every Overpass endpoint is rate-limiting us.');
        }

        throw $unreachable ?? new ConnectionException('No Overpass endpoint configured.');
    }

    /** @return list<ScoutRequest> */
    private function quadrants(ScoutRequest $request): array
    {
        $midLat = ($request->south + $request->north) / 2;
        $midLng = ($request->west + $request->east) / 2;

        $box = static fn (float $s, float $w, float $n, float $e): ScoutRequest => new ScoutRequest(
            regionKey: $request->regionKey, south: $s, west: $w, north: $n, east: $e, locale: $request->locale,
        );

        return [
            $box($request->south, $request->west, $midLat, $midLng),
            $box($request->south, $midLng, $midLat, $request->east),
            $box($midLat, $request->west, $request->north, $midLng),
            $box($midLat, $midLng, $request->north, $request->east),
        ];
    }

    public function normalize(array $raw, string $locale): array
    {
        $candidates = [];

        foreach ($raw as $element) {
            $tags = $element['tags'] ?? [];
            $type = OsmTagMap::map($tags);

            if ($type === null) {
                continue;
            }

            // Unnamed elements are only useful as practical infrastructure
            // (a toilet needs no name; an unnamed "castle" is tag noise).
            $name = $tags['name'] ?? null;
            if ($name === null && $type->domain() !== PlaceTypeDomain::Practical) {
                continue;
            }

            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lng = $element['lon'] ?? $element['center']['lon'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }

            $externalRefs = array_filter([
                'wikidata' => $tags['wikidata'] ?? null,
                'wikipedia' => $tags['wikipedia'] ?? null,
            ]);

            $candidates[] = $this->candidate(
                externalId: $element['type'].'/'.$element['id'],
                name: $name ?? $type->value,
                altNames: array_filter([
                    $tags["name:{$locale}"] ?? '',
                    $tags['name:en'] ?? '',
                    $tags['alt_name'] ?? '',
                    $tags['old_name'] ?? '',
                ]),
                lat: (float) $lat,
                lng: (float) $lng,
                type: $type,
                sourceTags: $tags,
                externalRefs: $externalRefs,
                language: $locale,
            );
        }

        return $candidates;
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D'); // static places: weeks (PRD §9.3)
    }

    /**
     * One query covering exactly the primary tags OsmTagMap maps — the adapter
     * never fetches what normalize() would discard.
     */
    private function overpassQuery(ScoutRequest $request): string
    {
        $bbox = $request->bboxAsString();

        $selectors = [
            '["historic"]',
            '["tourism"~"^(viewpoint|museum|gallery|artwork)$"]',
            '["natural"~"^(waterfall|beach|cave_entrance|cliff|spring|rock|stone)$"]',
            '["natural"="water"]["water"="lake"]',
            '["craft"~"^(winery|brewery|distillery|pottery|goldsmith|jeweller|leather|shoemaker|watchmaker|glassblower|carpenter|bookbinder)$"]',
            '["amenity"~"^(place_of_worship|restaurant|cafe|marketplace|theatre|concert_hall|cinema|arts_centre|fountain|pharmacy|toilets|charging_station|shelter)$"]',
            '["leisure"~"^(park|garden|beach_resort|sports_centre|stadium|sauna)$"]',
            '["man_made"="tower"]',
            '["shop"~"^(bakery|deli|cheese|books|antiques|chocolate|confectionery|coffee|tea|wine)$"]',
            '["place"="square"]',
        ];

        $body = implode('', array_map(
            static fn (string $selector): string => "nwr{$selector}({$bbox});",
            $selectors,
        ));

        return "[out:json][timeout:120];({$body});out center tags;";
    }
}
