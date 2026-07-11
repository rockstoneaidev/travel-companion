<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Events\ExploreSessionEnded;
use App\Domain\Trips\Exceptions\ExploreSessionAlreadyEnded;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;

/**
 * `POST /api/v1/explore-sessions/{session}/end`. The trip stays active — a trip
 * outlives its sessions, that is what it is for (PRD §6.6).
 */
final class EndExploreSession
{
    public function __invoke(ExploreSession $session): ExploreSession
    {
        if (! $session->status->isLive()) {
            throw new ExploreSessionAlreadyEnded($session->id, $session->status);
        }

        $session->forceFill([
            'status' => ExploreSessionStatus::Ended,
            'ended_at' => CarbonImmutable::now(),
        ])->save();

        ExploreSessionEnded::dispatch($session->id, $session->trip_id, $session->user_id);

        return $session;
    }
}
