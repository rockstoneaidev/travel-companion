<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Enums\LlmTier;
use App\Domain\Agent\Exceptions\GenerationFailed;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Services\ClaimGuard;
use Illuminate\Support\Carbon;

/**
 * The machine reviewer (CURATION §4).
 *
 * ===========================================================================
 *  WHY THIS IS ALLOWED, WHEN "THE LLM IS NEVER A SOURCE OF FACTS"
 * ===========================================================================
 *
 * Because it is not being asked for a fact. It is asked a question about two pieces
 * of text: does this evidence span state what this claim asserts? That is entailment
 * — a comparison — and it is the one thing a model can do here without becoming the
 * thing we refuse to let it be. It never says whether the chapel IS 12th-century. It
 * says whether anybody we can cite ever said so.
 *
 * The distinction is the whole product. A claim we serve carries a source, a licence
 * and a retrieval date, so it can be attributed, re-checked and corrected. A claim
 * from a model's memory would be just as fluent and utterly untraceable — and the
 * person it fails is someone who walked twenty minutes on our say-so.
 *
 * ===========================================================================
 *  WHY IT REPLACES THE HUMAN, AND WHAT IT DOES NOT REPLACE
 * ===========================================================================
 *
 * The human gate had approved 149 items and rejected zero. A check that always says
 * yes is not a check; it is a delay. And it was never the interesting work: reading a
 * claim beside its evidence and asking "is this in there?" is mechanical, which is
 * exactly why it was so easy to rubber-stamp at midnight.
 *
 * What the machine does NOT decide is whether a place is worth telling anyone about.
 * That is taste, and it belongs upstream in the candidate selector and the scoring
 * model — not in a per-item yes/no clicked 149 times.
 *
 * Deterministic gates run FIRST and cost nothing (ClaimGuard). A model is never asked
 * a question that can be answered by looking at the row.
 */
final class VerifyCuratedItem
{
    public const PROMPT = 'claim_verification.v1';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ClaimGuard $guard,
    ) {}

    /**
     * Verify one item and record the verdict. Does NOT change status — deciding what
     * to do about a verdict is a policy question, and policy lives in the caller
     * (AutoReviewCuratedItem) so that an audit run can verify without approving
     * anything at all.
     *
     * @return array<string, mixed> the verdict, as stored
     */
    public function __invoke(CuratedItem $item): array
    {
        $violations = $this->guard->violations($item);

        if ($violations !== []) {
            // A gate failure is final. It does not go to the model: no amount of
            // well-supported prose makes an ungrounded claim servable, and asking
            // costs money to be told something we already knew.
            return $this->record($item, [
                'supported' => false,
                'gate_violations' => $violations,
                'assertions' => [],
                'reason' => 'Failed a deterministic gate: '.implode('; ', $violations),
                'verifier' => 'guard',
            ]);
        }

        try {
            $result = $this->llm->generate(
                systemPrompt: (string) file_get_contents(__DIR__.'/../../Agent/Prompts/'.self::PROMPT.'.md'),
                userPrompt: $this->userPrompt($item),
                schema: self::schema(),
                // Cheap on purpose. This is a comparison, not a composition — and a
                // verifier that costs as much as the writer would make the whole
                // pipeline twice the price to buy back a founder's evenings.
                tier: LlmTier::Cheap,
                promptVersion: self::PROMPT,
            );
        } catch (GenerationFailed $e) {
            // A verifier that cannot answer must never be read as approval. Silence is
            // "send it to a human", every time.
            return $this->record($item, [
                'supported' => false,
                'gate_violations' => [],
                'assertions' => [],
                'reason' => 'The verifier could not answer ('.$e->getMessage().') — a human should read this one.',
                'verifier' => 'error',
            ]);
        }

        return $this->record($item, [
            'supported' => (bool) ($result->output['supported'] ?? false),
            'gate_violations' => [],
            'assertions' => $result->output['assertions'] ?? [],
            'reason' => (string) ($result->output['reason'] ?? ''),
            'verifier' => 'llm',
            'model' => $result->model,
        ]);
    }

    private function userPrompt(CuratedItem $item): string
    {
        $evidence = '';

        foreach ((array) $item->evidence as $i => $source) {
            if (! is_array($source)) {
                continue;
            }

            $n = $i + 1;
            $excerpt = trim((string) ($source['excerpt'] ?? ''));
            $from = (string) ($source['source'] ?? 'unknown');

            $evidence .= "[{$n}] ({$from}) {$excerpt}\n\n";
        }

        return <<<PROMPT
        PLACE: {$item->title}

        CLAIM UNDER REVIEW:
        {$item->claim}

        EVIDENCE (this is the entirety of what may support the claim):
        {$evidence}
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    private function record(CuratedItem $item, array $verdict): array
    {
        $item->forceFill([
            'verdict' => $verdict,
            'verified_at' => Carbon::now(),
            'verifier_version' => self::PROMPT,
        ])->save();

        return $verdict;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['supported', 'assertions', 'reason'],
            'properties' => [
                'supported' => ['type' => 'boolean'],
                'assertions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['assertion', 'supported'],
                        'properties' => [
                            'assertion' => ['type' => 'string'],
                            'supported' => ['type' => 'boolean'],
                            /*
                             * Verbatim, or empty. A verifier that cannot quote the span it
                             * relied on has not verified anything — it has agreed.
                             *
                             * NOT `['string', 'null']`: Gemini's response_schema is proto-
                             * backed and rejects union types outright (HTTP 400, "Unknown
                             * name type"). Every verification failed that way on the first
                             * real run — safely, into "ask a human", which is the one
                             * direction a broken verifier is allowed to fail.
                             */
                            'evidence_span' => ['type' => 'string'],
                        ],
                    ],
                ],
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    /** True when this item's stored verdict says every assertion is supported. */
    public static function passes(CuratedItem $item): bool
    {
        return is_array($item->verdict) && ($item->verdict['supported'] ?? false) === true;
    }

    /** The statuses an item can be in and still be worth verifying. */
    public static function verifiable(): array
    {
        return [CurationStatus::InReview, CurationStatus::Draft];
    }
}
