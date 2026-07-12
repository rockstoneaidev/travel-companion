<?php

declare(strict_types=1);

namespace App\Domain\Sources\Exceptions;

use RuntimeException;

/**
 * Overpass said 429 — "too often", not "too big" (E13).
 *
 * Its own exception type because the remedy is the opposite of the one for a 504.
 * A 504 means the question was too big, and the answer is a smaller question. A 429
 * means we are asking too often, and splitting the box would answer it by asking
 * FOUR TIMES as often — a feedback loop straight into a ban on a volunteer service
 * we do not pay for.
 *
 * Observed for real: Stockholm's boxes earned a 429 while running strictly ONE AT A
 * TIME. Overpass's cost is a function of the query, not of our concurrency, so there
 * is no amount of politeness that makes hammering safe. You wait, or you go away.
 */
final class OverpassRateLimited extends RuntimeException {}
