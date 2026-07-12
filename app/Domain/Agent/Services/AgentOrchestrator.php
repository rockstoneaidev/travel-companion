<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Data\EvidenceBundle;
use App\Domain\Agent\Data\GenerationResult;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;
use App\Domain\Places\Services\CacheKeys;
use App\Enums\AppealFacet;
use Illuminate\Support\Facades\Cache;

/**
 * The only way another module asks for a generation (conventions/10: other
 * modules do not build prompts themselves).
 *
 * Three things live here, and each of them is a rule rather than an optimisation:
 *
 *   · The prompt is a FILE, versioned by its filename. Changing the prose is
 *     bumping the version — including "just a typo", because a typo fix that
 *     changes output is indistinguishable from a regression if the version did
 *     not move.
 *   · The cache key is (prompt_version, bundle_id), and the bundle id is a hash
 *     of the evidence. So a prompt bump invalidates, and new evidence invalidates,
 *     and nobody has to remember either.
 *   · An empty bundle never reaches the model. There is nothing to phrase, and
 *     asking anyway is asking it to invent.
 */
final class AgentOrchestrator
{
    private const SUMMARY_PROMPT = 'opportunity_summary.v1';

    private const SUMMARY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            // Not decoration: the model naming its sources is what lets us see,
            // after the fact, whether it actually used them.
            'grounded_in' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['summary', 'grounded_in'],
    ];

    private const CLAIM_PROMPT = 'curated_claim.v1';

    /**
     * A method rather than a const, because the facet enum is the single source
     * of truth for the vocabulary and a const cannot call into it. Adding a facet
     * to the taxonomy therefore widens the schema automatically.
     *
     * @return array<string, mixed>
     */
    private static function claimSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'claim' => ['type' => 'string'],
                'facets' => [
                    'type' => 'array',
                    // Constrained to the taxonomy (TAXONOMY §4): the model may not
                    // invent a facet, because facets drive who gets shown the item.
                    'items' => ['type' => 'string', 'enum' => AppealFacet::values()],
                ],
                'grounded_in' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence_note' => ['type' => 'string'],
            ],
            'required' => ['title', 'claim', 'facets', 'grounded_in', 'confidence_note'],
        ];
    }

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * The card's "why now" line. Returns null when we have nothing to say — the
     * caller then shows the template, which is dull and true.
     */
    public function opportunitySummary(EvidenceBundle $bundle, string $placeName): ?GenerationResult
    {
        if ($bundle->isEmpty()) {
            return null;   // nothing written about this place; silence is the honest answer
        }

        $key = CacheKeys::llm(self::SUMMARY_PROMPT, $bundle->id());

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return new GenerationResult(
                output: $cached['output'],
                promptVersion: self::SUMMARY_PROMPT,
                model: $cached['model'],
                inputTokens: 0,   // a cache hit costs nothing, and must not claim to
                outputTokens: 0,
                cached: true,
            );
        }

        try {
            $result = $this->llm->generate(
                systemPrompt: $this->prompt(self::SUMMARY_PROMPT),
                userPrompt: $this->userPrompt($bundle, $placeName),
                schema: self::SUMMARY_SCHEMA,
                tier: LlmTier::Cheap,
                promptVersion: self::SUMMARY_PROMPT,
            );
        } catch (GenerationFailed) {
            // The template is always available and always true. A failed
            // generation degrades the voice, never the feed (conventions/10).
            return null;
        }

        if (! is_string($result->output['summary'] ?? null) || trim($result->output['summary']) === '') {
            return null;
        }

        Cache::put($key, ['output' => $result->output, 'model' => $result->model], now()->addDays(30));

        return $result;
    }

    /**
     * A pack draft for the review queue (CURATION §3 step 2, E14).
     *
     * Runs on the CAPABLE tier, unlike the card summary. The economics are
     * inverted here: a card summary is read once and costs a fraction of a cent,
     * while a bad pack draft costs a founder's minutes in review — and review
     * hours are the scarcest resource in this project. Spending more per token to
     * spend fewer minutes per item is the right trade.
     *
     * Returns null when there is nothing to draft from. A curator's queue full of
     * empty drafts is worse than a shorter queue.
     */
    public function curatedClaim(EvidenceBundle $bundle, string $placeName, string $placeType): ?GenerationResult
    {
        if ($bundle->isEmpty()) {
            return null;
        }

        $key = CacheKeys::llm(self::CLAIM_PROMPT, $bundle->id());

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return new GenerationResult(
                output: $cached['output'],
                promptVersion: self::CLAIM_PROMPT,
                model: $cached['model'],
                inputTokens: 0,
                outputTokens: 0,
                cached: true,
            );
        }

        try {
            $result = $this->llm->generate(
                systemPrompt: $this->prompt(self::CLAIM_PROMPT),
                userPrompt: <<<PROMPT
                PLACE: {$placeName}
                TYPE: {$placeType}

                EVIDENCE (this is everything you know about this place):
                {$bundle->toPrompt()}
                PROMPT,
                schema: self::claimSchema(),
                tier: LlmTier::Capable,
                promptVersion: self::CLAIM_PROMPT,
            );
        } catch (GenerationFailed) {
            return null;
        }

        if (! is_string($result->output['claim'] ?? null) || trim($result->output['claim']) === '') {
            return null;
        }

        Cache::put($key, ['output' => $result->output, 'model' => $result->model], now()->addDays(30));

        return $result;
    }

    private function userPrompt(EvidenceBundle $bundle, string $placeName): string
    {
        // Note what is NOT here: no distance, no score, no "this is a great match
        // for you". The app owns the numbers and the judgement; the model gets the
        // evidence and the name, and that is all it can possibly repeat back.
        return <<<PROMPT
        PLACE: {$placeName}

        SITUATION:
        {$bundle->context->toPrompt()}

        EVIDENCE (this is everything you know about this place):
        {$bundle->toPrompt()}
        PROMPT;
    }

    private function prompt(string $version): string
    {
        $path = __DIR__.'/../Prompts/'.$version.'.md';

        return (string) file_get_contents($path);
    }
}
