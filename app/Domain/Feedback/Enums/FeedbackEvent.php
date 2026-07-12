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

    /** Whether this event teaches the taste profile (SCORING §4.1's η table). */
    public function teachesTaste(): bool
    {
        return $this !== self::VisitPromptDeclined;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
