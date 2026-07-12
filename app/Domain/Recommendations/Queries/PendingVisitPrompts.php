<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Recommendations\Data\VisitPromptData;
use App\Domain\Recommendations\Models\Recommendation;
use Illuminate\Support\Facades\DB;

/**
 * "Were you there?" (SCREENS S4).
 *
 * We ask only where the question is honest: the user tapped *Take me* and
 * actually started navigation, enough time has passed that they could have
 * arrived, and they have not already answered. Everything else would be a
 * survey, and the prompt only works if it reads like a friend asking.
 *
 * The proximity half of the rule (~150 m) is applied by the client, which is
 * the only party that knows where the user is standing right now — Phase 1 is
 * foreground-only and stores no background location (PRD §8, §16). This query
 * hands over the place's coordinates so it can.
 */
final class PendingVisitPrompts
{
    /** @return list<VisitPromptData> */
    public function forUser(int $userId): array
    {
        $minMinutes = (int) config('trips.visit_prompt.min_minutes_since_take_me');

        $rows = Recommendation::query()
            ->where('recommendations.user_id', $userId)
            // They said "Take me" and we handed them off to maps.
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('recommendation_feedback as took')
                ->whereColumn('took.recommendation_id', 'recommendations.id')
                ->where('took.event', FeedbackEvent::Accepted->value)
                ->where('took.metadata->started_navigation', 'true')
                ->where('took.occurred_at', '<=', now()->subMinutes($minMinutes)))
            // …and they have not already answered, either way.
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('recommendation_feedback as answered')
                ->whereColumn('answered.recommendation_id', 'recommendations.id')
                ->whereIn('answered.event', [
                    FeedbackEvent::Visited->value,
                    FeedbackEvent::VisitPromptDeclined->value,
                ]))
            ->orderByDesc('served_at')
            ->limit(3)
            ->get(['id', 'score_inputs']);

        $out = [];
        foreach ($rows as $row) {
            $candidate = $row->score_inputs['candidate'] ?? null;

            if ($candidate === null || ! isset($candidate['lat'], $candidate['lng'], $candidate['name'])) {
                continue;
            }

            $out[] = new VisitPromptData(
                recommendationId: $row->id,
                placeName: (string) $candidate['name'],
                lat: (float) $candidate['lat'],
                lng: (float) $candidate['lng'],
            );
        }

        return $out;
    }
}
