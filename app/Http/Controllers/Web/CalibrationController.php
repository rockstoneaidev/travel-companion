<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Profiles\Actions\CompleteCalibration;
use App\Domain\Profiles\Actions\RecordCalibrationChoice;
use App\Domain\Profiles\Queries\CalibrationProgress;
use App\Domain\Profiles\Services\CalibrationContent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Onboarding taste calibration (SCREENS S9, ONBOARDING.md).
 *
 * Content is served from the BACKEND — never hard-coded in the client
 * (ONBOARDING §4). The pair set is versioned, and a frontend copy of it would
 * drift from `calibration_version` the first time anyone edited a caption.
 *
 * The flow is interruptible on purpose: each choice posts as it is made, so
 * killing the app mid-flow resumes at the next unanswered pair rather than
 * starting over. Sixty seconds of someone's attention is not something to ask
 * for twice.
 */
final class CalibrationController extends Controller
{
    public function welcome(Request $request, CalibrationProgress $progress): Response|RedirectResponse
    {
        if ($progress->isComplete((int) $request->user()->id)) {
            return to_route('explore.index');
        }

        return Inertia::render('calibrate/welcome');
    }

    public function pair(Request $request, int $number, CalibrationContent $content, CalibrationProgress $progress): Response|RedirectResponse
    {
        $userId = (int) $request->user()->id;
        $pair = $content->pair($number);

        if ($pair === null) {
            return to_route('calibrate.practical');
        }

        return Inertia::render('calibrate/pair', [
            // The facet vectors are NOT here. They are the answer key: a user who
            // can see that one card is "offbeat" stops telling us their taste and
            // starts telling us what they want us to think.
            'pair' => $pair->toArray(),
            'total' => $content->count(),
            'answered' => $number - 1,
        ]);
    }

    public function choose(Request $request, int $number, CalibrationContent $content, RecordCalibrationChoice $record): RedirectResponse
    {
        $validated = $request->validate([
            // null = skipped. A skip is an answer, and it teaches nothing.
            'side' => ['nullable', Rule::in(['a', 'b'])],
        ]);

        $pair = $content->pair($number);

        if ($pair === null) {
            return to_route('calibrate.practical');
        }

        $record((int) $request->user()->id, $pair, $validated['side'] ?? null);

        return $number >= $content->count()
            ? to_route('calibrate.practical')
            : to_route('calibrate.pair', ['number' => $number + 1]);
    }

    public function practical(CalibrationContent $content): Response
    {
        return Inertia::render('calibrate/practical', [
            'practicals' => $content->practicals(),
        ]);
    }

    public function complete(Request $request, CompleteCalibration $complete): RedirectResponse
    {
        $validated = $request->validate([
            // Both skippable; the profile's column defaults stand (walk 15, band 2).
            'walk_minutes' => ['nullable', 'integer', Rule::in([10, 20, 40])],
            'price_band' => ['nullable', 'integer', Rule::in([1, 2, 3])],
        ]);

        $complete(
            (int) $request->user()->id,
            $validated['walk_minutes'] ?? null,
            $validated['price_band'] ?? null,
        );

        return to_route('explore.index');
    }
}
