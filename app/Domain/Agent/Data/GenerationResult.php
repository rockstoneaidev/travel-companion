<?php

declare(strict_types=1);

namespace App\Domain\Agent\Data;

/**
 * One generation, with everything needed to answer for it later (PRD §15.1).
 *
 * `output` is the validated structured response — never raw text we then parse
 * with a regex (conventions/10: a schema violation is a failed generation, not
 * something to pattern-match your way out of).
 */
final readonly class GenerationResult
{
    /** @param array<string, mixed> $output */
    public function __construct(
        public array $output,
        public string $promptVersion,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public bool $cached = false,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
