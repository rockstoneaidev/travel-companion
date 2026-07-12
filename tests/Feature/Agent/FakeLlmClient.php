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
        /** What the claim verifier should answer (claim_verification.*). */
        private readonly bool $supported = true,
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

        // Each prompt has its own schema, and a fake that ignores that is a fake
        // that passes tests the real client would fail.
        $output = match (true) {
            str_starts_with($promptVersion, 'curated_claim') => [
                'title' => 'Backstreet workshop',
                'claim' => $this->summary,
                'facets' => ['craft'],
                'grounded_in' => ['datatourisme'],
                'confidence_note' => '',
            ],
            str_starts_with($promptVersion, 'claim_verification') => [
                'supported' => $this->supported,
                'assertions' => [[
                    'assertion' => 'the claim',
                    'supported' => $this->supported,
                    'evidence_span' => $this->supported ? 'a quoted span from the evidence' : null,
                ]],
                'reason' => $this->supported ? 'Every assertion is in the evidence.' : 'The date is not in the evidence.',
            ],
            default => ['summary' => $this->summary, 'grounded_in' => ['curated']],
        };

        return new GenerationResult(
            output: $output,
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
