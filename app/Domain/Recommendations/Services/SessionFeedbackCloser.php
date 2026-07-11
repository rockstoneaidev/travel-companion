<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Recommendations\Actions\RecordFeedback;
use App\Domain\Recommendations\Models\Recommendation;

/**
 * SCREENS.md action table: "card served, no interaction → ignored (batched on
 * session end)". Ambiguity is worth almost nothing (η .02) but it is not
 * worth zero — and the trace must say the card was seen and passed over.
 */
final class SessionFeedbackCloser
{
    public function __construct(
        private readonly FeedbackLedger $ledger,
        private readonly RecordFeedback $record,
    ) {}

    public function closeSession(string $sessionId): int
    {
        $recommendations = Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->whereNotNull('served_at')
            ->get();

        if ($recommendations->isEmpty()) {
            return 0;
        }

        $withFeedback = $this->ledger->eventsForRecommendations($recommendations->pluck('id')->all());

        $ignored = 0;
        foreach ($recommendations as $recommendation) {
            if (! isset($withFeedback[$recommendation->id])) {
                ($this->record)($recommendation, FeedbackEvent::Ignored, ['batched' => 'session_end']);
                $ignored++;
            }
        }

        return $ignored;
    }
}
