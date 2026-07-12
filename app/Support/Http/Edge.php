<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Domain\Sources\Services\CircuitBreaker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The SERVE lane's HTTP policy: fail fast, degrade honestly, never make a user wait.
 *
 * This class exists to be READ more than to be called. The edge services already do
 * the right thing — short timeout, no retry, wrapped in a {@see CircuitBreaker}
 * with a typed fallback — and the risk is that someone, having correctly learned that
 * "APIs need exponential backoff", comes along and adds it here.
 *
 * DO NOT. On this path a retry is a regression.
 *
 * A user is standing on a street corner waiting for a feed. Backing off and retrying
 * converts a fast feed that is missing its weather into a slow feed, and the whole
 * scoring model is built so that the first of those is fine: every edge signal is
 * OPTIONAL, a missing term drops out of the weighted sum and discounts confidence
 * (SCORING §2.5). As CircuitBreaker puts it — "a recommendation missing its weather is
 * a recommendation; a recommendation that never arrives is not."
 *
 * So the failure policy on this lane is:
 *
 *   1. A short timeout, because the latency budget is the constraint.
 *   2. NO retry. One attempt. The signal is optional; the user's patience is not.
 *   3. A circuit breaker, so the 40th user in a bad minute does not pay the timeout
 *      to learn what the 1st already found out.
 *   4. A fallback VALUE, not an exception — the caller has nothing useful to do with
 *      an exception except swallow it.
 *
 * Retry belongs on the ingest lane, where nobody is waiting and the cost of giving up
 * is a corpus that is quietly wrong. See {@see Harvest}, and note that these two
 * policies are deliberately opposite: retry policy follows the LANE, not the vendor.
 * Google Routes and Wikipedia are both "an API"; that fact tells you nothing about how
 * to handle their failures.
 */
final class Edge
{
    /** @param array<string, string> $headers */
    public static function request(int $timeoutSeconds, array $headers = []): PendingRequest
    {
        // No ->retry(). That is the policy, not an omission.
        return Http::timeout($timeoutSeconds)->withHeaders($headers);
    }
}
