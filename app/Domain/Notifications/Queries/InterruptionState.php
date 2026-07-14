<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Queries;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Notifications\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is true about this person, right now — the policy's other half.
 *
 * The BUDGET IS DERIVED, not stored. PRD §14.2 names a `notification_budget` table and this
 * deliberately is not one: a counter can drift from the thing it counts, and a budget that
 * disagrees with the ledger is worse than no budget — it is confidently wrong, in the one
 * place where being wrong means spamming somebody.
 *
 * `count(*) WHERE sent_at::date = today` cannot drift. It is the same rows the replayer
 * reads, and it is the same rows an auditor would read.
 */
final class InterruptionState
{
    /**
     * How long a "not for me" is remembered by the PUSH policy.
     *
     * A month, not forever, and deliberately: people change, and a companion that never
     * forgives is a companion that slowly narrows into a rut. This is a gate on
     * interruption, not on the feed — they can still find the thing themselves.
     */
    private const REJECTION_MEMORY_DAYS = 30;

    /** @return array{sent_today: int, last_sent_at: ?CarbonImmutable, sent_recently: int, rejected_domains: list<string>} */
    public function forUser(int $userId, CarbonImmutable $at): array
    {
        $sent = Notification::query()
            ->where('user_id', $userId)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $at->startOfDay())
            ->orderByDesc('sent_at')
            ->get(['sent_at']);

        $window = $at->subHours((int) config('notifications.interruption_penalty.recent_window_hours'));

        return [
            'sent_today' => $sent->count(),
            'last_sent_at' => $sent->first()?->sent_at,
            'sent_recently' => $sent->filter(fn ($n): bool => $n->sent_at->greaterThanOrEqualTo($window))->count(),
            'rejected_domains' => $this->recentlyRejectedDomains($userId, $at),
        ];
    }

    /**
     * Type-domains this user has said "not for me" to lately.
     *
     * Asking again is not persistence, it is not listening. The window is deliberately short
     * — a month, not forever: people change, and a taste profile that never forgives is a
     * taste profile that slowly narrows into a rut.
     *
     * @return list<string>
     */
    private function recentlyRejectedDomains(int $userId, CarbonImmutable $at): array
    {
        // Aliased, because `pluck()` reads a PROPERTY off the row — hand it a raw JSON
        // expression and it looks for a column literally named `score_inputs->'candidate'…`.
        return DB::table('recommendation_feedback')
            ->join('recommendations', 'recommendations.id', '=', 'recommendation_feedback.recommendation_id')
            ->where('recommendations.user_id', $userId)
            ->where('recommendation_feedback.event', FeedbackEvent::Dismissed->value)
            ->where('recommendation_feedback.occurred_at', '>=', $at->subDays(self::REJECTION_MEMORY_DAYS))
            ->selectRaw("recommendations.score_inputs->'candidate'->>'type_domain' AS type_domain")
            ->pluck('type_domain')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
