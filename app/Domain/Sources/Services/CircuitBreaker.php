<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use Closure;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-source circuit breaker (E16, PRD §14.3).
 *
 * Edge sources sit on the READ path: a user is standing on a street waiting for a
 * feed. When Google or Open-Meteo is having a bad day, the wrong behaviour is to
 * make every one of them wait out the timeout, one after another, to learn the
 * same thing we learned thirty seconds ago. The right behaviour is to stop asking
 * for a while and serve the feed without that signal — a recommendation missing
 * its weather is a recommendation; a recommendation that never arrives is not.
 *
 * This is why every edge signal in this codebase is OPTIONAL by construction: the
 * sub-scores drop a missing term out of the weighted sum and discount confidence
 * (SCORING §2.5). The breaker is what makes "missing" cheap.
 *
 * Deliberately simple: N failures inside a window opens it for a cool-off, and the
 * next call after that is a live probe. No half-open bookkeeping, no state machine
 * to get wrong — the cost of an extra failed probe is one timeout.
 */
final class CircuitBreaker
{
    private const FAILURE_THRESHOLD = 5;

    private const FAILURE_WINDOW_SECONDS = 120;

    private const OPEN_SECONDS = 120;

    /**
     * Run $work unless the circuit for $source is open; on failure, return $fallback.
     *
     * The fallback is a VALUE, not an exception, because the caller is on a read
     * path and has nothing useful to do with an exception except swallow it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  T  $fallback
     * @return T
     */
    public function call(string $source, Closure $work, mixed $fallback): mixed
    {
        if ($this->isOpen($source)) {
            return $fallback;
        }

        try {
            $result = $work();

            Cache::forget($this->failureKey($source));   // a success clears the streak

            return $result;
        } catch (Exception $e) {
            // Exception, NOT Throwable — and the difference is not pedantry.
            //
            // Throwable also catches Error: a TypeError, a call to a property that
            // does not exist, any plain bug in the closure. Swallowing those turns
            // "I broke the code" into "the source is flaky", degrades the feed
            // silently, and eventually trips the breaker on a fault that has nothing
            // to do with the network. It cost me twenty minutes on this very file.
            //
            // A source being down is an Exception. A bug is not a source being down.
            $this->recordFailure($source, $e);

            return $fallback;
        }
    }

    public function isOpen(string $source): bool
    {
        return Cache::get($this->openKey($source)) !== null;
    }

    private function recordFailure(string $source, Throwable $e): void
    {
        $failures = (int) Cache::get($this->failureKey($source), 0) + 1;

        Cache::put($this->failureKey($source), $failures, self::FAILURE_WINDOW_SECONDS);

        if ($failures < self::FAILURE_THRESHOLD) {
            return;
        }

        Cache::put($this->openKey($source), true, self::OPEN_SECONDS);
        Cache::forget($this->failureKey($source));

        // Opening a breaker is not a routine event — it means a signal has gone dark
        // for every user at once, and every feed served meanwhile is quietly poorer.
        Log::warning("circuit breaker OPEN for [{$source}]", [
            'cool_off_seconds' => self::OPEN_SECONDS,
            'last_error' => $e->getMessage(),
        ]);
    }

    private function failureKey(string $source): string
    {
        return "breaker:{$source}:failures";
    }

    private function openKey(string $source): string
    {
        return "breaker:{$source}:open";
    }
}
