<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Recommendations\Actions\RecordFeedback;
use App\Domain\Recommendations\Models\Recommendation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Inertia twin of Api\V1\RecommendationFeedbackController — same action. */
final class RecommendationFeedbackController extends Controller
{
    public function store(Request $request, Recommendation $recommendation, RecordFeedback $record): RedirectResponse
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'event' => ['required', Rule::enum(FeedbackEvent::class)],
            'metadata' => ['sometimes', 'array'],
        ]);

        $record($recommendation, FeedbackEvent::from($validated['event']), $validated['metadata'] ?? []);

        return back();
    }
}
