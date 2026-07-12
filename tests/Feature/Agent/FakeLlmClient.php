<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;

/**
 * The port's whole point: in tests nothing reaches the network, generations are
 * deterministic, and we can assert on exactly what the model was shown.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<array{system: string, user: string, tier: LlmTier, prompt_version: string}> */
    public array $calls = [];

    public function __construct(
        private readonly ?string $summary = 'A quiet courtyard behind the church.',
        private readonly bool $fails = false,
    ) {}

    public function generate(
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        LlmTier $tier,
        string $promptVersion,
    ): GenerationResult {
        $this->calls[] = [
            'system' => $systemPrompt,
            'user' => $userPrompt,
            'tier' => $tier,
            'prompt_version' => $promptVersion,
        ];

        if ($this->fails) {
            throw GenerationFailed::transport($promptVersion, 'fake failure');
        }

        return new GenerationResult(
            output: ['summary' => $this->summary, 'grounded_in' => ['curated']],
            promptVersion: $promptVersion,
            model: 'fake-model',
            inputTokens: 100,
            outputTokens: 20,
        );
    }

    public function lastUserPrompt(): string
    {
        return end($this->calls)['user'] ?? '';
    }
}
