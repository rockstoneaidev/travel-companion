<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Profiles\Services\TasteProfiles;
use App\Domain\Recommendations\Models\Recommendation;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * One feedback event, end to end (PRD §14.5, SCREENS action table): append to
 * the ledger, teach the taste profile from the served candidate's facets.
 * The ~5 s dismiss undo is client-side (SCREENS S1) — by the time this runs,
 * the user meant it.
 */
final class RecordFeedback
{
    public function __construct(
        private readonly FeedbackLedger $ledger,
        private readonly TasteProfiles $profiles,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  ?DateTimeInterface  $occurredAt  When the user actually tapped, which is
     *                                          NOT when we hear about it: S11 queues feedback in a dead zone
     *                                          and flushes it on reconnect. Recording the flush time would put
     *                                          a lie in the moat — and "Were you there?" reasons about elapsed
     *                                          time, so it would be a lie with consequences. Untrusted input;
     *                                          the caller clamps it.
     */
    public function __invoke(Recommendation $recommendation, FeedbackEvent $event, array $metadata = [], ?DateTimeInterface $occurredAt = null): void
    {
        $at = $occurredAt ?? now();
        $facets = $recommendation->score_inputs['candidate']['facets'] ?? [];

        DB::transaction(function () use ($recommendation, $event, $metadata, $at, $facets): void {
            $retraction = $this->contradicted($recommendation, $event);

            if ($retraction !== null) {
                $this->ledger->record($recommendation->id, $retraction, ['retracted_by' => $event->value], $at);
                $this->profiles->learnFromFeedback($recommendation->user_id, $retraction, $facets);
            }

            $this->ledger->record($recommendation->id, $event, $metadata, $at);
            $this->profiles->learnFromFeedback($recommendation->user_id, $event, $facets);
        });
    }

    /**
     * KEEPING AND DISMISSING ARE OPPOSITE VERDICTS ON THE SAME THING.
     *
     * The ledger tracks them as two independent pairs — `saved`/`unsaved` and
     * `dismissed`/`undismissed` — and nothing used to reconcile them, so dismissing
     * something you had kept left BOTH live. The item then listed itself under "Still
     * possible" and under "Not for me" on the same screen (S6), which is the product
     * telling the user two contradictory things about what it thinks they want.
     *
     * So the later verdict retracts the earlier one. We do it by APPENDING the
     * retraction, never by deleting the keep or the dismissal: the stream is the moat
     * (PRD §14.5), and "changed their mind at 09:55" is itself a signal worth having.
     *
     * The retraction is routed through the learner too, and that is deliberate rather
     * than incidental. `Unsaved` teaches nothing (housekeeping — FeedbackEvent), so
     * retracting a keep costs the profile nothing. `Undismissed` runs the learner
     * BACKWARDS (FacetWeightLearner::retract), so keeping something you had dismissed
     * un-teaches the "fewer like this" the dismissal taught — which is exactly what the
     * user just told us.
     */
    private function contradicted(Recommendation $recommendation, FeedbackEvent $event): ?FeedbackEvent
    {
        $state = $this->ledger->toggleStateFor($recommendation->id);

        return match (true) {
            $event === FeedbackEvent::Dismissed && $state['kept'] => FeedbackEvent::Unsaved,
            $event === FeedbackEvent::Saved && $state['dismissed'] => FeedbackEvent::Undismissed,
            default => null,
        };
    }
}
