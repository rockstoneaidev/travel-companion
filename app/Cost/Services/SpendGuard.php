<?php

declare(strict_types=1);

namespace App\Cost\Services;

use App\Cost\Mail\SpendCapAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The kill-switch (docs/COST.md §8).
 *
 * Attribution tells you who cost you €4,000 from a looping client — the morning
 * after. A cap means it never happens. With users in the field and one operator,
 * this file is worth more than the entire dashboard, and it is why it ships first.
 *
 * ---------------------------------------------------------------------------
 *  A tripped cap DEGRADES. It never stops.
 * ---------------------------------------------------------------------------
 *
 * The voice falls back to the template — which is always true, just duller — and
 * routing falls back to the estimator, whose number is the one we persist on the
 * trace anyway. Both fallbacks already existed and were already correct, so the
 * panic path is not a new code path at all: it is the *normal* path, chosen early.
 * That is the only reason a hard cap is safe to have.
 *
 * Counters live in Redis, keyed by day. They are not the accounting — `cost_events`
 * is — they are a fast approximate gate. If Redis loses them we under-count for a
 * day and the ledger still knows the truth. That trade is deliberate: a guard that
 * needs a SQL aggregate on every paid call would itself become the cost problem.
 */
final class SpendGuard
{
    /** Long enough to survive a day boundary in any timezone, short enough to self-clean. */
    private const COUNTER_TTL_SECONDS = 172_800;   // 48h

    /**
     * Is spending blocked right now?
     *
     * Checked before a paid call, never after. A cap that is enforced after the money
     * is spent is a report, not a cap.
     */
    public function blocked(?int $userId = null): bool
    {
        if ($this->paused()) {
            return true;
        }

        if ($this->spentTodayMicros() >= $this->dailyCapMicros()) {
            return true;
        }

        if ($userId !== null && $this->spentTodayByUserMicros($userId) >= $this->perUserCapMicros()) {
            return true;
        }

        return false;
    }

    /**
     * Book spend against today's counters, and shout if we crossed a threshold.
     *
     * Called by the ledger at flush, so a call site can never forget: if it lands in
     * `cost_events`, it counts against the cap.
     */
    public function record(int $micros, ?int $userId = null): void
    {
        if ($micros <= 0) {
            return;
        }

        $before = $this->spentTodayMicros();

        $this->increment($this->globalKey(), $micros);

        if ($userId !== null) {
            $this->increment($this->userKey($userId), $micros);
        }

        $this->alertIfThresholdCrossed($before, $before + $micros);
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function spentTodayMicros(): int
    {
        return (int) Cache::get($this->globalKey(), 0);
    }

    public function spentTodayByUserMicros(int $userId): int
    {
        return (int) Cache::get($this->userKey($userId), 0);
    }

    public function dailyCapMicros(): int
    {
        return PriceBook::usdToMicros((float) config('cost.caps.daily_usd'));
    }

    public function perUserCapMicros(): int
    {
        return PriceBook::usdToMicros((float) config('cost.caps.per_user_daily_usd'));
    }

    /**
     * The manual switch (COST.md §7.4) — superadmin, audit-logged at the call site.
     *
     * Deliberately NOT a config value: config is a deploy, and the whole point of a
     * manual pause is that it works at 2am from a phone. Cap VALUES stay in config,
     * because changing what "too much" means should be a reviewed decision; stopping
     * the bleeding should not.
     */
    public function pause(): void
    {
        Cache::put($this->pauseKey(), true, self::COUNTER_TTL_SECONDS);
        Log::warning('cost: paid calls paused manually');
    }

    public function resume(): void
    {
        Cache::forget($this->pauseKey());
        Log::warning('cost: paid calls resumed manually');
    }

    public function paused(): bool
    {
        return (bool) Cache::get($this->pauseKey(), false);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Increment, and make sure the key expires.
     *
     * `Cache::add` first so the TTL is set exactly once, on creation. Calling put()
     * on every increment would push the expiry forward forever and the counter would
     * outlive its day — which, for a daily cap, means the cap silently becomes a
     * lifetime cap.
     */
    private function increment(string $key, int $micros): void
    {
        Cache::add($key, 0, self::COUNTER_TTL_SECONDS);
        Cache::increment($key, $micros);
    }

    /**
     * The day, in the ONE timezone both the cap and the /admin strip read (COST.md
     * §7.2). A strip that says "$3 today" while the breaker thinks the day rolled over
     * an hour ago is a strip nobody trusts twice.
     */
    private function today(): string
    {
        return now()->timezone((string) config('cost.timezone'))->format('Y-m-d');
    }

    private function globalKey(): string
    {
        return 'cost:day:'.$this->today();
    }

    private function userKey(int $userId): string
    {
        return 'cost:day:'.$this->today().':user:'.$userId;
    }

    private function pauseKey(): string
    {
        return 'cost:paused';
    }

    /**
     * Fire once per threshold per day. The dashboard is for mornings; this is for
     * everything else.
     */
    private function alertIfThresholdCrossed(int $before, int $after): void
    {
        if (! (bool) config('cost.alerts.enabled')) {
            return;
        }

        $cap = $this->dailyCapMicros();

        if ($cap <= 0) {
            return;
        }

        foreach ((array) config('cost.alerts.thresholds') as $fraction) {
            $threshold = (int) round($cap * (float) $fraction);

            if ($before >= $threshold || $after < $threshold) {
                continue;   // not crossed on THIS flush
            }

            // `add()` is the once-per-day latch: it returns false if the key exists,
            // so a second worker crossing the same threshold in the same second does
            // not send a second mail.
            $latch = 'cost:alert:'.$this->today().':'.$fraction;

            if (! Cache::add($latch, true, self::COUNTER_TTL_SECONDS)) {
                continue;
            }

            $percent = (int) round((float) $fraction * 100);
            $spentUsd = $after / 1_000_000;
            $capUsd = $cap / 1_000_000;

            Log::warning('cost: daily cap threshold crossed', [
                'threshold' => $fraction,
                'spent_usd' => round($spentUsd, 4),
                'cap_usd' => round($capUsd, 2),
            ]);

            $to = (string) config('cost.alerts.to');

            if ($to === '') {
                continue;
            }

            Mail::to($to)->send(new SpendCapAlert($percent, $spentUsd, $capUsd));
        }
    }
}
