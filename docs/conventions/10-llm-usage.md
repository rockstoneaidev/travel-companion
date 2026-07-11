# 10 — LLM usage

The most important sentence in this document, from CLAUDE.md and PRD §3:

> **The LLM is never a source of facts.**

Not opening hours, not prices, not distances, not whether a place exists, not whether a vineyard
uses 200-year-old methods. Facts come from sources ([09](09-source-adapters.md)). The LLM's job is
to *phrase* what the evidence already says, and to make judgements the evidence supports.

A model that "knows" a charming bakery in a village it has never seen data for is the single fastest
way to destroy this product's trust. The architecture exists to make that structurally impossible,
not merely discouraged.

## Where LLM code lives

`app/Domain/Agent/` — the orchestrator, the prompts, the generation services. Other modules ask the
Agent module for generation; they do not build prompts themselves.

```
app/Domain/Agent/
    Prompts/            Versioned prompt templates.
    Data/               EvidenceBundle, GenerationResult.
    Services/           AgentOrchestrator, EvidenceBundleBuilder.
    Contracts/          LlmClient — the port. Swappable, mockable in tests.
```

## The evidence bundle

**Every generation takes an evidence bundle as input, and the bundle is stored with the output**
(PRD §12).

```php
final readonly class EvidenceBundle
{
    /** @param list<EvidenceItem> $items */
    public function __construct(
        public string $bundleId,
        public array $items,          // each: source key, license, excerpt, url, retrieved_at
        public ContextData $context,  // time, weather, trip state — facts, not vibes
    ) {}
}
```

Rules:
- The prompt contains **only** what is in the bundle. No "you know about French wine regions" —
  that is an invitation to invent.
- The system prompt says so explicitly: *generate only from the provided evidence; if the evidence
  does not support a claim, omit it; never state opening hours, prices or distances that are not in
  the evidence.*
- Numbers, times and distances are **injected by the application** into the response template where
  possible, not generated. The safest fact is one the model never had to repeat.
- The bundle is persisted and linked to the recommendation. Given a recommendation, you can always
  answer "what did the model actually see?" — that is the whole point of PRD §15.

## Versioning

`prompt_version` is recorded on **every generation**, in the same row as the output
([03](03-migrations-and-schema.md), PRD §15.1).

- Prompts are files under `Prompts/`, versioned by name (`opportunity_blurb.v3.md`), not strings
  interpolated in a service.
- **Changing a prompt is bumping its version.** No exceptions, including "just fixing a typo" — a
  typo fix that changes output is indistinguishable from a regression if the version didn't move.
- The trip replayer (PRD §15.2) replays traces under a chosen `prompt_version`. That only works if
  the version is honest.

## Calling the model

- Everything goes through the `LlmClient` contract. No SDK calls scattered through domain code.
- **Structured output.** Ask for a schema, validate the response against it, and treat a validation
  failure as a failed generation — not as something to regex your way out of.
- **Every call is a queued job** ([08](08-jobs-and-queues.md)), with an explicit timeout, explicit
  retries, and a cost record. Never call a model synchronously inside a web request; a user waiting
  on a model is a user watching a spinner.
- **Log tokens and cost per recommendation** (PRD §11). A recommendation that cost €0.40 to generate
  and was ignored is a number the team needs to be able to see.
- Cache generations until the underlying evidence changes (PRD §9.3). The cache key includes the
  bundle id and the `prompt_version` — a prompt bump correctly invalidates.

## The LLM never decides delivery

CLAUDE.md constraint 4: **deterministic policy gates all delivery.** The model does not decide when
to interrupt the user, does not decide whether something is worth a notification, and does not
decide ranking. It writes the text of something the deterministic pipeline has already chosen to
surface.

`NotificationPolicy` (Phase 2) is plain, testable, versioned PHP. Not a prompt.

Likewise "unusualness" is **computed from signals** (PRD §9.5), never asserted by a model. If you
find yourself asking the LLM "is this a hidden gem?", stop — that is a scoring input, and it must
be computed and stored so it can be tuned against real acceptance data.

## Evaluation

- Prompt changes are validated against **gold traces** (PRD §15.2) before merge, once the replayer
  exists. "It looked better in one example" is not evidence.
- Hallucination checks belong in the test suite: given a bundle with no opening hours, the output
  must not contain opening hours. This is testable, so test it.

## Checklist

- [ ] Input is a stored evidence bundle. No model prior is relied on.
- [ ] The output claims nothing the bundle does not support.
- [ ] `prompt_version` recorded on the row; the prompt is a versioned file.
- [ ] Structured output, schema-validated.
- [ ] Queued, timed out, retried, cost-logged.
- [ ] The model chose the words. The pipeline chose the recommendation.
