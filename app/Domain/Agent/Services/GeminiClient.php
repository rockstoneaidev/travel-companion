<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;
use App\Domain\Recommendations\Services\CostMeter;
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
 * Tokens are reported to the CostMeter, so a recommendation carries what it cost
 * to produce (PRD §11) — the number you need before anyone asks whether an
 * ignored recommendation was worth €0.40.
 */
final class GeminiClient implements LlmClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(private readonly CostMeter $cost) {}

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

        $this->cost->recordLlmTokens($inputTokens + $outputTokens);

        return new GenerationResult(
            output: $output,
            promptVersion: $promptVersion,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
