<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a batch of recommendations was served (E46, PRD §8.1).
 *
 * A session used to have exactly one serve, so "why" had one possible answer and
 * needed no column. Now the feed is alive — it re-anchors when you walk, refills
 * when you dismiss, and re-serves when you ask — so every batch has to be able to
 * say which of those it was. Without it the trace (PRD §15.1) can tell you what we
 * served and not why we served it *again*, and the replayer cannot tell a move from
 * a refresh.
 */
enum ServeReason: string
{
    use HasOptions;

    /** The first feed of the session — the only serve that used to exist. */
    case Initial = 'initial';

    /** The user moved far enough that the old anchor was no longer where they are (§9.2). */
    case MoveReanchor = 'move_reanchor';

    /** "Fresh picks from here" — user-triggered, no drift required. */
    case ManualRefresh = 'manual_refresh';

    /** Dismissals shrank the feed; topped back up to feed_size from the next candidates. */
    case DismissBackfill = 'dismiss_backfill';

    /**
     * The user went looking, and picked this one themselves (E51).
     *
     * Worth its own reason, and not folded into `initial`: a place WE put in front of
     * somebody and a place THEY went and found are different events, and the learner will
     * one day want to tell them apart. A card the user chose out of ninety-nine is a much
     * stronger statement of taste than a card we chose for them — and an `ignored` on one
     * we never showed would be meaningless.
     */
    case Browse = 'browse';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial feed',
            self::MoveReanchor => 'You moved',
            self::ManualRefresh => 'Fresh picks from here',
            self::DismissBackfill => 'Topped up after a dismissal',
            self::Browse => 'You went looking for this one',
        };
    }

    /**
     * Does this reason open a NEW batch, or append to the current one?
     *
     * A backfill is not a new menu — it is the same menu with the gap filled, so it
     * joins the current serve group at the next free position. A move or a refresh
     * replaces the menu, so it starts a group of its own and the previous one is
     * frozen as a trace.
     *
     * A BROWSE pick appends for the same reason a backfill does, and it matters more here:
     * the user did not move and we did not re-rank — they reached into the candidate set we
     * already had and pulled one out. Starting a new batch would throw away the five cards
     * they were looking at a moment ago, which is the opposite of what "show me more" means.
     */
    public function opensNewGroup(): bool
    {
        return $this !== self::DismissBackfill && $this !== self::Browse;
    }
}
