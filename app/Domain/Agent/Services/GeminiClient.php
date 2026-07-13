<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Cost\Services\CostMeter;
use App\Cost\Services\SpendGuard;
use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Gemini behind the LlmClient port (PRD Appendix A).
 *
 * Structured output is not a nicety here: `responseSchema` makes the model emit
 * JSON that satisfies our schema, and anything that does not is a FAILED
 * generation. We never parse prose, and we never regex our way to a summary —
 * a malformed response means the caller falls back to the template, which is
 * always true even when it is dull.
 *
 * Tokens are reported to the CostMeter with their SPLIT intact — input, output and
 * cached input are three different prices, so a summed count cannot be turned back
 * into money (docs/COST.md §9, bug 3: this file used to add them together one line
 * after extracting them separately).
 */
final class GeminiClient implements LlmClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly CostMeter $cost,
        private readonly SpendGuard $guard,
    ) {}

    public function generate(
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        LlmTier $tier,
        string $promptVersion,
    ): GenerationResult {
        $model = $tier->model();
        $key = (string) config('services.gemini.key');

        if ($key === '') {
            throw GenerationFailed::transport($promptVersion, 'no GEMINI_API_KEY configured');
        }

        /*
         * The spend cap, checked BEFORE the call — a cap enforced after the money is
         * gone is a report, not a cap (COST.md §8).
         *
         * It throws the same GenerationFailed every other failure here throws, and that
         * is the point: every caller already handles it by falling back to the template,
         * which is always true, just duller. The degradation path is not a new code path
         * written in a panic; it is the existing one, chosen early.
         */
        if ($this->guard->blocked($this->cost->userId())) {
            throw GenerationFailed::transport($promptVersion, 'daily spend cap reached — falling back to the template');
        }

        try {
            $response = Http::timeout((int) config('services.gemini.timeout', 30))
                ->retry(2, 2000, throw: false)
                ->withHeaders(['x-goog-api-key' => $key])
                ->post(sprintf(self::ENDPOINT, $model), [
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                        // Low, not zero: we want plain prose, not a lottery. The
                        // grounding comes from the evidence, not from determinism.
                        'temperature' => 0.4,
                    ],
                ]);
        } catch (Throwable $e) {
            throw GenerationFailed::transport($promptVersion, $e->getMessage());
        }

        if ($response->failed()) {
            throw GenerationFailed::transport($promptVersion, "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            // A refusal, a safety block, or an empty candidate. All the same to us.
            throw GenerationFailed::transport($promptVersion, 'empty candidate: '.json_encode($response->json('promptFeedback')));
        }

        try {
            $output = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw GenerationFailed::schema($promptVersion, $e->getMessage());
        }

        if (! is_array($output)) {
            throw GenerationFailed::schema($promptVersion, 'response was not an object');
        }

        $usage = $response->json('usageMetadata') ?? [];
        $inputTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0);

        // Cached input is billed at its own (lower) rate, and it is the rate that will
        // matter most if the Phase 3 chat ever lands — a multi-turn conversation
        // re-sends its context every turn, so cached input becomes the dominant term
        // (COST.md §5.1). Read it now; a token class we never captured is a bill we
        // cannot explain.
        $cachedInputTokens = (int) ($usage['cachedContentTokenCount'] ?? 0);

        // `promptTokenCount` INCLUDES the cached portion. Billing the whole thing at
        // the uncached rate and then billing the cached part again would double-count
        // exactly the tokens that are supposed to be cheap.
        $freshInputTokens = max(0, $inputTokens - $cachedInputTokens);

        $this->cost->recordLlm(
            model: $model,
            inputTokens: $freshInputTokens,
            outputTokens: $outputTokens,
            cachedInputTokens: $cachedInputTokens,
            promptVersion: $promptVersion,
        );

        return new GenerationResult(
            output: $output,
            promptVersion: $promptVersion,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
