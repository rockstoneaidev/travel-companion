<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Recommendations\Actions\RecordFeedback;
use App\Domain\Recommendations\Models\Recommendation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\ResolvesFeedbackTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The Inertia twin of Api\V1\RecommendationFeedbackController — same action. */
final class RecommendationFeedbackController extends Controller
{
    use ResolvesFeedbackTime;

    public function store(Request $request, Recommendation $recommendation, RecordFeedback $record): RedirectResponse|Response
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

        // The offline queue flushes with fetch(), not an Inertia visit: a redirect
        // would have it follow a 302 and re-download a page nobody is looking at.
        return $request->expectsJson() ? response()->noContent() : back();
    }
}
