<?php

declare(strict_types=1);

namespace App\Domain\Agent\Contracts;

use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;

/**
 * The port (conventions/10). Every model call in this codebase goes through it —
 * no SDK calls scattered through domain code, and swapping provider is a config
 * change, not a refactor.
 *
 * It is also what makes the "never a source of facts" rule testable: in tests the
 * client is a fake, so a generation is deterministic and nothing reaches the
 * network.
 */
interface LlmClient
{
    /**
     * @param  string  $systemPrompt  the rules — including "generate only from the evidence"
     * @param  string  $userPrompt  the evidence bundle, and nothing else
     * @param  array<string, mixed>  $schema  JSON Schema; the response is validated against it and a
     *                                        violation is a FAILED generation, never something to
     *                                        regex your way out of
     *
     * @throws GenerationFailed
     */
    public function generate(
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        LlmTier $tier,
        string $promptVersion,
    ): GenerationResult;
}
