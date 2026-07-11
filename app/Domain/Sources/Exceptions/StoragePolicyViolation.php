<?php

declare(strict_types=1);

namespace App\Domain\Sources\Exceptions;

use RuntimeException;

/**
 * Thrown when edge-only data (Google & friends) is about to be persisted into
 * a world-model table. This throws — it does not warn (conventions/09). A
 * write like this is a licensing incident, not a code-review nit.
 */
final class StoragePolicyViolation extends RuntimeException
{
    public static function edgeOnlyPersistence(string $sourceKey): self
    {
        return new self(sprintf(
            'Source "%s" is edge-only: its data must never be persisted into any world-model table (conventions/09, ODBL-REVIEW §6).',
            $sourceKey,
        ));
    }
}
