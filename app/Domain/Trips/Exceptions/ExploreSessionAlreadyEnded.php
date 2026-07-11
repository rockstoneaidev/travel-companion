<?php

declare(strict_types=1);

namespace App\Domain\Trips\Exceptions;

use App\Domain\Trips\Enums\ExploreSessionStatus;
use DomainException;

/**
 * Mapped to HTTP once, in bootstrap/app.php (conventions/01) — the domain does
 * not know it is behind HTTP.
 */
final class ExploreSessionAlreadyEnded extends DomainException
{
    public function __construct(
        public readonly string $exploreSessionId,
        public readonly ExploreSessionStatus $status,
    ) {
        parent::__construct("Explore session {$exploreSessionId} is already {$status->value}.");
    }
}
