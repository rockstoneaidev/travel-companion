<?php

declare(strict_types=1);

namespace App\Domain\Agent\Exceptions;

use RuntimeException;

/**
 * A generation that cannot be trusted is a generation that did not happen.
 *
 * Thrown on transport failure, on a refusal, and — importantly — on a response
 * that does not satisfy the schema. Callers fall back to the template
 * (conventions/10); they never salvage a malformed response.
 */
final class GenerationFailed extends RuntimeException
{
    public static function schema(string $promptVersion, string $detail): self
    {
        return new self("Generation {$promptVersion} failed schema validation: {$detail}");
    }

    public static function transport(string $promptVersion, string $detail): self
    {
        return new self("Generation {$promptVersion} failed: {$detail}");
    }
}
