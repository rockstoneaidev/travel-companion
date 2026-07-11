<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Trips\Enums\TravelMode;

/**
 * Stage A of the tiered travel-time strategy (PRD §10): crow-flies distance
 * × mode speed × path factor. Cheap enough to gate hundreds of candidates
 * per request; Stage B (real routing) runs only for served items, at the
 * edge (E16). Pure — every estimate is recomputable from the trace.
 */
final class TravelTimeEstimator
{
    /** @var array<string, array{speed_kmh: float, path_factor: float}> */
    private readonly array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('scoring.stage_a');
    }

    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): float
    {
        $modeConfig = $this->config[$mode->value];
        $pathKm = $this->haversineKm($fromLat, $fromLng, $toLat, $toLng) * $modeConfig['path_factor'];

        return round($pathKm / $modeConfig['speed_kmh'] * 60, 2);
    }

    public function haversineKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);

        $h = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($h)));
    }
}
