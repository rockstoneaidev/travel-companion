<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The INGEST lane's HTTP policy: retry hard, back off politely, resume later.
 *
 * ===========================================================================
 *  WHY THERE ARE TWO POLICIES AND NOT ONE
 * ===========================================================================
 *
 * "Retry with backoff on every API call" sounds like an unambiguous good and is not.
 * This codebase has two lanes with OPPOSITE requirements, and applying one policy to
 * both breaks whichever one it wasn't designed for.
 *
 *   · The SERVE lane ({@see Edge}) — Google Routes, Places hours, weather at ranking
 *     time. A person is standing on a street waiting for a feed. Backing off here
 *     turns "a fast feed missing its weather" into "a slow feed", which is strictly
 *     worse: every edge signal is optional by construction precisely so that failure
 *     is CHEAP (SCORING §2.5). Fail fast, degrade honestly, let the breaker stop the
 *     bleeding.
 *
 *   · The HARVEST lane (this class) — Wikipedia, Wikidata, Commons, DATAtourisme,
 *     Mérimée. Nobody is waiting. The cost of giving up is not a slow response, it is
 *     a corpus that is quietly wrong for six weeks. Retry hard.
 *
 * Retry policy follows the LANE, not the vendor.
 *
 * ===========================================================================
 *  WHAT THIS FIXES, AND WHAT IT DELIBERATELY DOES NOT
 * ===========================================================================
 *
 * Backoff alone would NOT have prevented the Stockholm bug, and it is worth being
 * precise about that or we will congratulate ourselves for the wrong fix. Given
 * perfect backoff, `FetchWikipediaExtracts` still exhausted its retries and still
 * returned `[]` — "no article" — for a place whose article exists. The corruption was
 * identical; backoff would only have made it rarer, which is arguably worse, because
 * a rare silent lie is harder to catch than a common one.
 *
 * The load-bearing fix is {@see Outcome}: UNKNOWN is not ABSENT. Backoff reduces how
 * often we land in UNKNOWN. The three-state result is what stops UNKNOWN being
 * written to the database as a fact.
 *
 * ===========================================================================
 *  FULL JITTER — not decoration
 * ===========================================================================
 *
 * The delay is random(0, min(cap, base · 2^n)), not base · 2^n.
 *
 * Without jitter, N ingest workers throttled by the same server back off for the same
 * duration and return in lockstep, re-colliding on exactly the request that throttled
 * them — a self-inflicted thundering herd that re-triggers the limit it was waiting
 * out. With concurrency, jitter is not a refinement; it is the difference between
 * backoff working and backoff looping.
 *
 * And when the server sends `Retry-After`, we believe it. It is the server telling us
 * the answer while our formula is guessing.
 */
final class Harvest
{
    private const MAX_ATTEMPTS = 5;

    private const BASE_DELAY_MS = 1_000;

    /** No single wait longer than this, however many times we have failed. */
    private const MAX_DELAY_MS = 30_000;

    /** A courtesy pause after every successful call, so we mostly never get throttled at all. */
    private const POLITENESS_MS = 200;

    /** Shout when less than a tenth of the window's budget is left. */
    private const BUDGET_WARN_FRACTION = 0.1;

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = [], array $headers = [], int $timeout = 60): HarvestResult
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->get($url, $query),
            $headers,
            $timeout,
        );
    }

    /** @param array<string, mixed> $form */
    public function postForm(string $url, array $form = [], array $headers = [], int $timeout = 60): HarvestResult
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->asForm()->post($url, $form),
            $headers,
            $timeout,
        );
    }

    /** @param array<string, mixed> $payload */
    public function postJson(string $url, array $payload = [], array $headers = [], int $timeout = 60): HarvestResult
    {
        return $this->send(
            fn (PendingRequest $request): Response => $request->post($url, $payload),
            $headers,
            $timeout,
        );
    }

    /**
     * @param  Closure(PendingRequest): Response  $perform
     * @param  array<string, string>  $headers
     */
    private function send(Closure $perform, array $headers, int $timeout): HarvestResult
    {
        $lastReason = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $perform(Http::timeout($timeout)->withHeaders($headers));
            } catch (ConnectionException $e) {
                // The network, not the server. Always worth another go.
                $lastReason = 'connection: '.$e->getMessage();
                $this->backOff($attempt, null);

                continue;
            } catch (Throwable $e) {
                // Something we do not understand. Do not retry into the dark.
                return new HarvestResult(Outcome::Unknown, null, $attempt, $e->getMessage());
            }

            $status = $response->status();

            $this->observeBudget($response);

            if ($response->successful()) {
                Sleep::for(self::POLITENESS_MS)->milliseconds();

                return new HarvestResult(Outcome::Ok, $response, $attempt);
            }

            // A FACT ABOUT THE WORLD: the server looked, and there is nothing there.
            // This is the only failure a caller may safely persist as absence.
            if ($status === 404 || $status === 410) {
                return new HarvestResult(Outcome::Absent, $response, $attempt);
            }

            /*
             * A WINDOW WE CANNOT WAIT OUT IS NOT WORTH FOUR MORE ATTEMPTS.
             *
             * DATAtourisme answers a 429 with `x-ratelimit-reset: 2997` — fifty minutes.
             * We backed off and retried five times anyway, which is five more requests
             * into a bucket that is already empty, several seconds apart, against a
             * window measured in the hour. It cannot succeed, and asking again while
             * being told to stop is precisely the behaviour that gets a key revoked.
             *
             * So when the server says WHEN, we believe it: if the reset is further away
             * than our longest backoff, we stop immediately and hand the caller the
             * number. The ingest degrades honestly (never as absence — see Outcome) and
             * the region can be re-run after the window turns over.
             */
            $retryAfter = $this->retryAfterSeconds($response);

            if ($status === 429 && $retryAfter !== null && $retryAfter * 1_000 > self::MAX_DELAY_MS) {
                return new HarvestResult(
                    Outcome::Unknown,
                    $response,
                    $attempt,
                    "http 429, rate limited for {$retryAfter}s",
                    $retryAfter,
                );
            }

            // Worth asking again: throttled briefly, or the server is having a moment.
            if ($status === 429 || $status >= 500) {
                $lastReason = "http {$status}";
                $this->backOff($attempt, $response);

                continue;
            }

            // 400/401/403 — we are asking wrong, or we are not welcome. Retrying
            // changes nothing, and it is still not evidence that the thing is absent.
            return new HarvestResult(Outcome::Unknown, $response, $attempt, "http {$status}");
        }

        return new HarvestResult(Outcome::Unknown, null, self::MAX_ATTEMPTS, $lastReason);
    }

    /** Exponential, capped, fully jittered — and overridden by Retry-After when offered. */
    private function backOff(int $attempt, ?Response $response): void
    {
        $retryAfter = $response !== null ? ($this->retryAfterSeconds($response) ?? 0) : 0;

        if ($retryAfter > 0) {
            Sleep::for(min($retryAfter * 1_000, self::MAX_DELAY_MS))->milliseconds();

            return;
        }

        $ceiling = min(self::MAX_DELAY_MS, self::BASE_DELAY_MS * (2 ** ($attempt - 1)));

        Sleep::for(random_int(0, $ceiling))->milliseconds();
    }

    /**
     * When the server says to come back. `Retry-After` is the standard; DATAtourisme
     * uses `x-ratelimit-reset` (seconds remaining in the window) and nothing else.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        foreach (['Retry-After', 'x-ratelimit-reset', 'ratelimit-reset'] as $header) {
            $value = (int) $response->header($header);

            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Say something BEFORE the bucket is empty.
     *
     * `x-ratelimit-remaining` is free information in every response, and nobody was
     * reading it. A pagination bug walked DATAtourisme's cursor off the end of Paris and
     * through the national catalogue, spending ~1,500 calls against a 1,000-call window;
     * the first anyone knew of it was a 429 wall and a region with no tourism-board layer
     * at all. The budget had been draining in plain sight, in a header, on every one of
     * those requests.
     *
     * A warning at 10% is not a fix — the fix is not to make 1,500 calls — but it is the
     * difference between noticing at the time and reconstructing it a day later from the
     * wreckage.
     */
    private function observeBudget(Response $response): void
    {
        $limit = (int) $response->header('x-ratelimit-limit');
        $remaining = (int) $response->header('x-ratelimit-remaining');

        if ($limit <= 0 || $response->header('x-ratelimit-remaining') === '') {
            return;   // the source does not publish a budget; nothing to watch
        }

        if ($remaining > $limit * self::BUDGET_WARN_FRACTION) {
            return;
        }

        Log::warning('harvest: rate-limit budget nearly spent', [
            'host' => $response->effectiveUri()?->getHost(),
            'remaining' => $remaining,
            'limit' => $limit,
            'resets_in_seconds' => $this->retryAfterSeconds($response),
        ]);
    }
}
