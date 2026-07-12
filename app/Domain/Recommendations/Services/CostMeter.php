<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

/**
 * What one request actually spent (PRD §14.3, §15.1).
 *
 * The cost fields on a recommendation used to be the literals `0` — true today,
 * because Phase 1 ranks off our own database with no paid API and no LLM. But a
 * hardcoded zero keeps reporting zero the day someone adds a Google verify call
 * or a Gemini generation on the serve path, and the first thing we would want to
 * know then is what it cost. So the zeros are now *measured*.
 *
 * Request-scoped: bound as a singleton, read once when the trace is written.
 */
final class CostMeter
{
    private int $apiCalls = 0;

    private int $llmTokens = 0;

    /** @var array<string, int> */
    private array $byHost = [];

    public function recordApiCall(string $host): void
    {
        $this->apiCalls++;
        $this->byHost[$host] = ($this->byHost[$host] ?? 0) + 1;
    }

    public function recordLlmTokens(int $tokens): void
    {
        $this->llmTokens += $tokens;
    }

    public function apiCalls(): int
    {
        return $this->apiCalls;
    }

    public function llmTokens(): int
    {
        return $this->llmTokens;
    }

    /** @return array<string, int> */
    public function byHost(): array
    {
        return $this->byHost;
    }

    public function reset(): void
    {
        $this->apiCalls = 0;
        $this->llmTokens = 0;
        $this->byHost = [];
    }
}
