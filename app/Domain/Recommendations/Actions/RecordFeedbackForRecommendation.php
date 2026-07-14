<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Recommendations\Contracts\FeedbackRecorder;
use App\Domain\Recommendations\Models\Recommendation;
use Carbon\CarbonImmutable;

/** The published surface of {@see RecordFeedback}: an id in, the whole ledger + learner behind it. */
final class RecordFeedbackForRecommendation implements FeedbackRecorder
{
    public function __construct(
        private readonly RecordFeedback $feedback,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function record(string $recommendationId, FeedbackEvent $event, array $metadata = [], ?CarbonImmutable $at = null): void
    {
        $recommendation = Recommendation::query()->find($recommendationId);

        if ($recommendation === null) {
            return;   // a receipt for a trace that no longer exists is not an error
        }

        ($this->feedback)($recommendation, $event, $metadata, $at);
    }
}
