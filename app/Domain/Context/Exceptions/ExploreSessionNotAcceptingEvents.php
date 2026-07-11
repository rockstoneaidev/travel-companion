<?php

declare(strict_types=1);

namespace App\Domain\Context\Exceptions;

use DomainException;

/** Mapped to HTTP once, in bootstrap/app.php (conventions/01). */
final class ExploreSessionNotAcceptingEvents extends DomainException
{
    public function __construct(public readonly string $exploreSessionId)
    {
        parent::__construct("Explore session {$exploreSessionId} is not accepting context events.");
    }
}
