<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Data portability (GDPR Art. 20, PRD §16) — "your travel memory belongs to you",
 * which is a positioning claim only if the export is real.
 *
 * Everything we hold about a person, in one JSON document they can actually read:
 * their profile, their trips, what we showed them, what they told us about it, and
 * what we concluded. Not a token gesture — the taste profile and the feedback
 * ledger are the two things they would most want to see, because those are the
 * two that decide what they get shown.
 */
final class ExportUserData
{
    /** @return array<string, mixed> */
    public function __invoke(int $userId): array
    {
        $user = DB::table('users')
            ->selectRaw('id, name, email, created_at, research_consent, home_zone_radius_meters,
                         ST_Y(home_zone_center::geometry) AS home_lat, ST_X(home_zone_center::geometry) AS home_lng')
            ->where('id', $userId)
            ->first();

        return [
            'exported_at' => now()->toIso8601String(),
            'privacy_policy_version' => config('privacy.version'),

            'account' => $user,

            // What we concluded about them — the thing that decides what they see.
            'taste_profile' => DB::table('user_taste_profiles')->where('user_id', $userId)->first(),
            'calibration_answers' => DB::table('profile_signals')->where('user_id', $userId)->get(),

            'trips' => DB::table('trips')->where('user_id', $userId)->get(),

            // Location fields are exported as they are STORED — which for anything
            // past the retention window means an H3 cell and not a coordinate. The
            // export tells the truth about what we kept, including that we let it go.
            'explore_sessions' => DB::table('explore_sessions')
                ->selectRaw('id, trip_id, status, origin_h3_index, time_budget_minutes, travel_mode, started_at, ended_at,
                             ST_Y(origin::geometry) AS origin_lat, ST_X(origin::geometry) AS origin_lng')
                ->where('user_id', $userId)
                ->get(),

            'context_events' => DB::table('context_events')
                ->selectRaw('id, occurred_at, h3_index, movement_mode, speed_mps, app_state,
                             ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
                ->where('user_id', $userId)
                ->get(),

            // What we showed them, and why (PRD §15 — explainability is a promise,
            // and a promise you cannot inspect is not one).
            'recommendations' => DB::table('recommendations')
                ->where('user_id', $userId)
                ->get(['id', 'opportunity_id', 'position', 'scores', 'score_inputs', 'scoring_model_version', 'served_at']),

            // ...and what they told us back. The moat is theirs too.
            'feedback' => DB::table('recommendation_feedback as f')
                ->join('recommendations as r', 'r.id', '=', 'f.recommendation_id')
                ->where('r.user_id', $userId)
                ->get(['f.recommendation_id', 'f.event', 'f.metadata', 'f.occurred_at']),
        ];
    }
}
