<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Places\Data\Coordinates;
use Carbon\CarbonImmutable;

/**
 * The context event payload (PRD §14.5). Every field but the session and the
 * timestamp is optional — "fields degrade gracefully when absent" is the spec,
 * not a convenience.
 */
final readonly class NewContextEventData
{
    /** @param list<string> $companions */
    public function __construct(
        public string $exploreSessionId,
        public ?CarbonImmutable $occurredAt = null,
        public ?Coordinates $location = null,
        public ?int $accuracyMeters = null,
        public ?MovementMode $movementMode = null,
        public ?float $speedMps = null,
        public ?int $heading = null,
        public ?AppState $appState = null,
        public ?float $batteryLevel = null,
        public ?bool $isLowPowerMode = null,
        public ?int $availableMinutes = null,
        public array $companions = [],
    ) {}

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt ?? CarbonImmutable::now();
    }
}
