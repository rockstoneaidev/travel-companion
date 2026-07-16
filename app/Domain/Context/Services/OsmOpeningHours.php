<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Data\OpeningHours;
use Carbon\CarbonImmutable;

/**
 * "Is it open?" answered from OSM's own `opening_hours` tag — for free (E50 cost lever).
 *
 * Most of the places we verify against Google Places carry an `opening_hours` tag in OSM
 * that we already ingested and were ignoring. Reading it costs nothing; a Google
 * `place_details` call costs $0.005. This answers the cases it can and hands the rest to
 * Google — so we stop paying to be told a café is open on a Tuesday afternoon.
 *
 * ## Two rules keep it honest, and they are the whole design
 *
 * A wrong "open" is worse than no answer (the same rule that governs the Google path). So:
 *
 *   1. **It only understands the simple, unambiguous grammar** — weekday ranges and clock
 *      times, plus `24/7`. Anything with public holidays, seasons, sunset, comments, or
 *      constrained weekdays (`Mo[1]`) it does not pretend to parse: it returns null and the
 *      caller falls through to Google. A parser that guesses at the hard grammar is a
 *      parser that quietly lies.
 *
 *   2. **It answers only when the answer survives a timezone it does not know.** OSM hours
 *      are LOCAL, and we do not reliably know a place's timezone. So the verdict must hold
 *      across a ±`margin` window: a place open 09:00–18:00 is confidently open at "14:00"
 *      (open across 11:30–16:30 too) and confidently shut at "03:00", but at "17:45" the
 *      window straddles closing — ambiguous — and that is exactly the near-boundary case
 *      where the countdown matters and Google earns its fee. We answer the easy middle,
 *      Google answers the edges.
 */
final class OsmOpeningHours
{
    private const DAYS = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 7];

    /**
     * Tokens whose presence means "richer than we will parse" — hand it to Google.
     * (Month names, holidays, sun events, week constraints, comments, `||` fallback.)
     */
    private const TOO_RICH = '/PH|SH|sunrise|sunset|dawn|dusk|week\b|easter|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|\|\||"|\[/i';

    /**
     * @param  string  $timezone  the place's local timezone. OSM hours are LOCAL, so `$at`
     *                            (which the pipeline holds in UTC) is converted to this before
     *                            it is compared. For the pilot every place is Central European
     *                            (Sweden, France), so one region timezone is exact; when the
     *                            product serves a place outside CET this MUST become a per-place
     *                            timezone or it will read hours in the wrong clock.
     * @param  int  $marginMinutes  residual uncertainty the verdict must still survive (DST
     *                              edges, minor within-region variance). Small, because the
     *                              timezone conversion has already done the heavy lifting.
     * @return OpeningHours|null null = "can't tell, ask Google"
     */
    public function evaluate(?string $spec, CarbonImmutable $at, string $timezone = 'UTC', int $marginMinutes = 60): ?OpeningHours
    {
        if ($spec === null) {
            return null;
        }

        $spec = trim($spec);

        if ($spec === '' || preg_match(self::TOO_RICH, $spec)) {
            return null;
        }

        if ($spec === '24/7') {
            return new OpeningHours(known: true, openNow: true);
        }

        $intervals = $this->parse($spec);

        if ($intervals === null) {
            return null;   // any unparseable fragment → give up cleanly
        }

        // Compare in the place's LOCAL clock; the margin then only has to absorb what the
        // timezone conversion could not (DST edges), not a whole 1–2 h offset.
        $local = $this->minuteOfWeek($at->setTimezone($timezone));
        $lo = $local - $marginMinutes;
        $hi = $local + $marginMinutes;

        $openAcross = $this->fullyOpen($intervals, $lo, $hi);
        $closedAcross = $this->fullyClosed($intervals, $lo, $hi);

        if ($openAcross) {
            // No closesAt: the timezone we don't know makes an exact countdown a guess, and
            // the daylight/light path already supplies an honest one. We assert only the fact
            // the margin guarantees — that it is open.
            return new OpeningHours(known: true, openNow: true);
        }

        if ($closedAcross) {
            return new OpeningHours(known: true, openNow: false);
        }

        return null;   // straddles a boundary — ambiguous, ask Google
    }

    /**
     * spec → open intervals as minute-of-week ranges, or null if any part is unparseable.
     *
     * @return list<array{int, int}>|null
     */
    private function parse(string $spec): ?array
    {
        $intervals = [];

        foreach (explode(';', $spec) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            // "<days> <times>" or just "<times>" (every day).
            if (preg_match('/^([A-Za-z,\- ]+?)\s+(.+)$/', $rule, $m) && $this->looksLikeDays($m[1])) {
                $days = $this->days($m[1]);
                $times = trim($m[2]);
            } else {
                $days = array_values(self::DAYS);   // no day token → every day
                $times = $rule;
            }

            if ($days === null) {
                return null;
            }

            if ($times === 'off' || $times === 'closed') {
                continue;   // an explicit closed day contributes no open interval
            }

            $ranges = $this->times($times);

            if ($ranges === null) {
                return null;
            }

            foreach ($days as $d) {
                foreach ($ranges as [$start, $end]) {
                    $base = ($d - 1) * 1440;
                    $intervals[] = [$base + $start, $base + $end];
                }
            }
        }

        return $intervals;
    }

    private function looksLikeDays(string $s): bool
    {
        return (bool) preg_match('/^(Mo|Tu|We|Th|Fr|Sa|Su|[,\- ])+$/', trim($s));
    }

    /** @return list<int>|null day numbers (1=Mon..7=Sun) */
    private function days(string $s): ?array
    {
        $out = [];

        foreach (explode(',', $s) as $part) {
            $part = trim($part);

            if (str_contains($part, '-')) {
                [$a, $b] = array_map('trim', explode('-', $part, 2));
                if (! isset(self::DAYS[$a], self::DAYS[$b])) {
                    return null;
                }
                for ($d = self::DAYS[$a]; $d !== self::DAYS[$b]; $d = $d % 7 + 1) {
                    $out[] = $d;
                }
                $out[] = self::DAYS[$b];
            } elseif (isset(self::DAYS[$part])) {
                $out[] = self::DAYS[$part];
            } else {
                return null;
            }
        }

        return $out;
    }

    /** @return list<array{int, int}>|null minute-of-day ranges */
    private function times(string $s): ?array
    {
        $out = [];

        foreach (explode(',', $s) as $range) {
            $range = trim($range);

            if (! preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $range, $m)) {
                return null;
            }

            $start = (int) $m[1] * 60 + (int) $m[2];
            $end = (int) $m[3] * 60 + (int) $m[4];

            // Overnight (22:00-02:00) crosses the day boundary — our minute-of-week model
            // does not, so we decline rather than mis-model it.
            if ($end <= $start) {
                return null;
            }

            $out[] = [$start, $end];
        }

        return $out;
    }

    /** Is [lo,hi] entirely inside some open interval? (handles week wrap) */
    private function fullyOpen(array $intervals, int $lo, int $hi): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($this->windowWithin($lo, $hi, $s, $e)) {
                return true;
            }
        }

        return false;
    }

    private function fullyClosed(array $intervals, int $lo, int $hi): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($this->windowTouches($lo, $hi, $s, $e)) {
                return false;
            }
        }

        return true;
    }

    /** Is the window [lo,hi] fully within [s,e], accounting for the 10080-minute week wrap? */
    private function windowWithin(int $lo, int $hi, int $s, int $e): bool
    {
        for ($k = -1; $k <= 1; $k++) {
            $shift = $k * 10080;
            if ($lo >= $s + $shift && $hi <= $e + $shift) {
                return true;
            }
        }

        return false;
    }

    /** Does the window [lo,hi] overlap [s,e] at all (week-wrapped)? */
    private function windowTouches(int $lo, int $hi, int $s, int $e): bool
    {
        for ($k = -1; $k <= 1; $k++) {
            $shift = $k * 10080;
            if ($lo < $e + $shift && $hi > $s + $shift) {
                return true;
            }
        }

        return false;
    }

    private function minuteOfWeek(CarbonImmutable $at): int
    {
        return ((int) $at->dayOfWeekIso - 1) * 1440 + $at->hour * 60 + $at->minute;
    }
}
