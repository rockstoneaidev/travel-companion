<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

            if ($response->successful()) {
                Sleep::for(self::POLITENESS_MS)->milliseconds();

                return new HarvestResult(Outcome::Ok, $response, $attempt);
            }

            // A FACT ABOUT THE WORLD: the server looked, and there is nothing there.
            // This is the only failure a caller may safely persist as absence.
            if ($status === 404 || $status === 410) {
                return new HarvestResult(Outcome::Absent, $response, $attempt);
            }

            // Worth asking again: throttled, or the server is having a moment.
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
        $retryAfter = $response !== null ? (int) $response->header('Retry-After') : 0;

        if ($retryAfter > 0) {
            Sleep::for(min($retryAfter * 1_000, self::MAX_DELAY_MS))->milliseconds();

            return;
        }

        $ceiling = min(self::MAX_DELAY_MS, self::BASE_DELAY_MS * (2 ** ($attempt - 1)));

        Sleep::for(random_int(0, $ceiling))->milliseconds();
    }
}
