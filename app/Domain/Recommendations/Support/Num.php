<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Support;

/** The four shared primitives (SCORING §3). Pure, deterministic, boring. */
final class Num
{
    public static function clamp(float $x): float
    {
        return max(0.0, min(1.0, $x));
    }

    /** 0 below $a, 1 above $b, linear between — readable off a trace. */
    public static function ramp(float $x, float $a, float $b): float
    {
        if ($b <= $a) {
            return $x >= $b ? 1.0 : 0.0;
        }

        return self::clamp(($x - $a) / ($b - $a));
    }

    /** Half-life decay: 2^(−t/H). */
    public static function decay(float $t, float $halfLife): float
    {
        return 2 ** (-$t / $halfLife);
    }

    /** Percentile rank of $x among $population (pct_tile's arithmetic; tile scoping is the caller's job). */
    public static function pct(float $x, array $population): float
    {
        if ($population === []) {
            return 0.5;
        }

        $below = count(array_filter($population, static fn (float $v): bool => $v < $x));

        return $below / count($population);
    }
}
