<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The fixed feedback vocabulary (PRD §14.5, SCORING.md §4.1, SCREENS.md).
 * Learning rates (η) belong to the learner and version under
 * profile_model_version — they live in the learner's config, not here.
 */
enum FeedbackEvent: string
{
    use HasOptions;

    case Accepted = 'accepted';    // "Take me" — weak positive
    case Saved = 'saved';          // "Keep" / "Remind me" — strong intent
    case Dismissed = 'dismissed';  // "Not for me" — explicit negative (undo-deferred ~5s client-side)
    case Visited = 'visited';      // "I was here" — the golden label
    case Ignored = 'ignored';      // served, no interaction — batched on session end

    /**
     * "Didn't go", answering the "Were you there?" prompt — and dismissing that
     * prompt records the same thing.
     *
     * Deliberately NOT a taste signal (SCREENS S4): the user *accepted* this
     * item, so not making it there says nothing about their taste. Wiring it to
     * `dismissed` would punish the item for the weather. It exists only so we
     * stop asking, and for funnel analytics.
     */
    case VisitPromptDeclined = 'visit_prompt_declined';

    /**
     * "Remove" on the KEPT screen (SCREENS S6) — the undo of `Saved`.
     *
     * It exists because the ledger is append-only: the moat is the stream, so we
     * retract a keep by recording the retraction, never by deleting the keep.
     *
     * Deliberately NOT a taste signal, and specifically not `Dismissed`. Clearing
     * a list is housekeeping, and the commonest reason to remove a kept item is
     * that you already went. Teaching "fewer like this" from that would punish an
     * item for being *acted on*, which is the exact inverse of the truth.
     */
    case Unsaved = 'unsaved';

    /**
     * "Show me these again" on the KEPT screen's dismissed list (SCREENS S6) — the
     * undo of `Dismissed`, once the ~5 s snackbar has closed and the POST has landed.
     *
     * Same append-only reasoning as `Unsaved`: a dismissal is retracted by recording
     * the retraction, never by deleting the dismissal. The ledger is the moat.
     *
     * It carries no η of its own — it is not a *positive* signal, and treating it as
     * one would let a mis-tap plus its correction net out to a taste opinion the user
     * never expressed. It instead *retracts* what the dismissal taught
     * (FacetWeightLearner::retract()), so a corrected mis-tap ends where it started.
     */
    case Undismissed = 'undismissed';

    /** Whether this event teaches the taste profile (SCORING §4.1's η table). */
    public function teachesTaste(): bool
    {
        return ! in_array($this, [self::VisitPromptDeclined, self::Unsaved, self::Undismissed], true);
    }

    /** The keep/un-keep pair, latest-wins, that decides what is on the KEPT screen. */
    public function togglesKeep(): bool
    {
        return in_array($this, [self::Saved, self::Unsaved], true);
    }

    /**
     * The dismiss/un-dismiss pair, latest-wins, that decides whether an item is
     * hidden from the feed (RankSession::feedFor) and listed as "Not for me" (S6).
     */
    public function togglesDismiss(): bool
    {
        return in_array($this, [self::Dismissed, self::Undismissed], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
