<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Context\Enums\PowerTier;
use App\Domain\Places\Data\Coordinates;
use Carbon\CarbonImmutable;

/**
 * One meaningful change, as the phone's summarizer saw it (PRD §13.4, E29).
 *
 * `location` is REQUIRED here, unlike the session-scoped payload where every field
 * degrades gracefully. A background event with no position is not a degraded event — it
 * is a wake-up with nothing to say, and it should never have left the phone.
 */
final readonly class NewTripContextData
{
    public function __construct(
        public Coordinates $location,
        public PowerTier $powerTier,
        public ?CarbonImmutable $occurredAt = null,
        public ?int $accuracyMeters = null,
        public ?MovementMode $movementMode = null,
        public ?float $speedMps = null,
        public ?int $heading = null,
        public AppState $appState = AppState::Background,
        public ?float $batteryLevel = null,
        public ?bool $isLowPowerMode = null,
    ) {}

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt ?? CarbonImmutable::now();
    }
}
