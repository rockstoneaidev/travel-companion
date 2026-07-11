<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Recommendations\Services\SessionFeedbackCloser;
use App\Domain\Trips\Events\ExploreSessionEnded;

/** Thin wrapper (conventions/08): the logic lives in the domain service. */
final class RecordIgnoredOnSessionEnd
{
    public function __construct(
        private readonly SessionFeedbackCloser $closer,
    ) {}

    public function handle(ExploreSessionEnded $event): void
    {
        $this->closer->closeSession($event->exploreSessionId);
    }
}
