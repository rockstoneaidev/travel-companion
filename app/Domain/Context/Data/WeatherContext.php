<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

/**
 * What the sky is doing over one tile (E16, PRD §9.2).
 *
 * Everything is nullable, and that is the design: a missing weather signal drops
 * out of the weighted sum and discounts confidence (SCORING §2.5). Weather is a
 * reason to nudge, never a reason to fail.
 */
final readonly class WeatherContext
{
    public function __construct(
        public ?float $temperatureC = null,
        public ?float $precipitationMm = null,
        /** WMO code (0 = clear). Open-Meteo's vocabulary, kept raw for the trace. */
        public ?int $weatherCode = null,
        public ?int $cloudCoverPercent = null,
    ) {}

    public function known(): bool
    {
        return $this->weatherCode !== null;
    }

    /** Raining, snowing, or otherwise wet enough that standing outside is worse. */
    public function isWet(): bool
    {
        return ($this->precipitationMm ?? 0.0) >= 0.2;
    }

    /**
     * Golden hour under a lid of cloud is not golden.
     *
     * The sun can be at exactly the right angle and the light can still be flat
     * grey — so the special-moment floor (SCORING §4.3) must not fire on geometry
     * alone. "The light is good right now" is a factual claim, and we do not make
     * factual claims we cannot support. When we have no weather at all we allow it:
     * a missing signal is not evidence of bad weather.
     */
    public function lightIsGood(): bool
    {
        if (! $this->known()) {
            return true;   // unknown ≠ overcast
        }

        return ! $this->isWet() && ($this->cloudCoverPercent ?? 0) < 70;
    }

    /** @return array<string, mixed> The decision trace (PRD §15). */
    public function toTrace(): array
    {
        return [
            'temp_c' => $this->temperatureC,
            'precip_mm' => $this->precipitationMm,
            'code' => $this->weatherCode,
            'cloud_pct' => $this->cloudCoverPercent,
        ];
    }
}
