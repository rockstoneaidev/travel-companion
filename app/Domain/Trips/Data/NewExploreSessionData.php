<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Enums\TravelMode;
use Carbon\CarbonImmutable;

/**
 * "I have 3 hours from here, heading that way" (PRD §6.6/§14.5) — the only
 * thing the user initiates.
 */
final readonly class NewExploreSessionData
{
    public function __construct(
        public int $userId,
        public Coordinates $origin,
        public int $timeBudgetMinutes,
        public TravelMode $travelMode,
        public ?int $heading = null,
        public ?Coordinates $destinationPoint = null,
        public ?CarbonImmutable $startedAt = null,
        /*
         * Defaulted to Device, and the default is the safety property: a session is
         * REAL unless something with the `location_emulate` permission deliberately
         * says otherwise (ADMIN §6). Nothing on the public API can set this — the
         * Form Request does not read it, so there is no field for a client to send.
         */
        public ContextSource $contextSource = ContextSource::Device,
    ) {}

    public function startedAt(): CarbonImmutable
    {
        return $this->startedAt ?? CarbonImmutable::now();
    }
}
