<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Recommendations\Actions\RecordFeedback;
use App\Domain\Recommendations\Models\Recommendation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\ResolvesFeedbackTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/recommendations/{recommendation}/feedback (PRD §14.5).
 * Thin wrapper (conventions/04): validate, authorize ownership, delegate.
 */
final class RecommendationFeedbackController extends Controller
{
    use ResolvesFeedbackTime;

    public function store(Request $request, Recommendation $recommendation, RecordFeedback $record): JsonResponse
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'event' => ['required', Rule::enum(FeedbackEvent::class)],
            'metadata' => ['sometimes', 'array'],
            // Set only by the offline queue, on flush (SCREENS S11).
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $record(
            $recommendation,
            FeedbackEvent::from($validated['event']),
            $validated['metadata'] ?? [],
            $this->occurredAt($validated['occurred_at'] ?? null),
        );

        return response()->json(status: 201);
    }
}
