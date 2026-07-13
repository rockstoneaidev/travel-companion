<?php

declare(strict_types=1);

use App\Domain\Feedback\Enums\FeedbackEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROPRIETARY-SHELL ZONE — a data repair on the feedback stream.
 *
 * Keeping and dismissing were tracked as two independent latest-wins pairs, and nothing
 * reconciled them, so dismissing an item you had already kept left BOTH verdicts live.
 * The item then appeared under "Still possible" AND under "Not for me" on the same
 * screen (S6) — the product telling the user two contradictory things at once.
 *
 * RecordFeedback now retracts the earlier verdict when the later one contradicts it.
 * That stops new contradictions; it cannot fix the ones already written. This does.
 *
 * The repair is an APPEND, like every other correction to this table: the stream is the
 * moat (PRD §14.5) and we do not rewrite it. For each contradictory recommendation we
 * append the retraction of whichever verdict came FIRST, stamped at the moment the
 * later one landed — because that is when the user actually changed their mind.
 *
 * ONE THING THIS DOES NOT DO. `Unsaved` teaches the taste profile nothing, so retracting
 * a stale keep (the dismiss-later case) is free. Retracting a stale DISMISSAL would want
 * FacetWeightLearner::retract() run against the profile as well, and a migration has no
 * business reaching into the learner or its consent gate. The keep-later branch below
 * therefore repairs the ledger but leaves the profile carrying what the dismissal taught.
 * It is written for completeness: at the time of writing that branch matches zero rows in
 * any environment, and the common case — dismiss-later — is the free one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $events = DB::table('recommendation_feedback')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['recommendation_id', 'event', 'occurred_at']);

        /** @var array<string, array{keep: ?object, dismiss: ?object}> $latest */
        $latest = [];

        foreach ($events as $row) {
            $event = FeedbackEvent::from($row->event);

            if ($event->togglesKeep()) {
                $latest[$row->recommendation_id]['keep'] = $row;
            }

            if ($event->togglesDismiss()) {
                $latest[$row->recommendation_id]['dismiss'] = $row;
            }
        }

        $now = now();
        $repairs = [];

        foreach ($latest as $recommendationId => $state) {
            $keep = $state['keep'] ?? null;
            $dismiss = $state['dismiss'] ?? null;

            $contradicted = $keep !== null
                && $dismiss !== null
                && $keep->event === FeedbackEvent::Saved->value
                && $dismiss->event === FeedbackEvent::Dismissed->value;

            if (! $contradicted) {
                continue;
            }

            // The later verdict is the one the user meant; the earlier one is retracted.
            $dismissedLast = $dismiss->occurred_at >= $keep->occurred_at;

            $repairs[] = [
                'recommendation_id' => $recommendationId,
                'event' => $dismissedLast ? FeedbackEvent::Unsaved->value : FeedbackEvent::Undismissed->value,
                'metadata' => json_encode([
                    'retracted_by' => $dismissedLast ? FeedbackEvent::Dismissed->value : FeedbackEvent::Saved->value,
                    'backfill' => 'keep_dismiss_exclusivity',
                ]),
                'occurred_at' => $dismissedLast ? $dismiss->occurred_at : $keep->occurred_at,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($repairs !== []) {
            DB::table('recommendation_feedback')->insert($repairs);
        }
    }

    public function down(): void
    {
        DB::table('recommendation_feedback')
            ->whereRaw("metadata->>'backfill' = ?", ['keep_dismiss_exclusivity'])
            ->delete();
    }
};
