<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Privacy\Actions\SetProfilingConsent;
use App\Domain\Privacy\Contracts\ProfilingConsent;
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
    public function welcome(Request $request, CalibrationProgress $progress, ProfilingConsent $consent): Response|RedirectResponse
    {
        $userId = (int) $request->user()->id;

        if ($progress->isComplete($userId)) {
            return to_route('explore.index');
        }

        return Inertia::render('calibrate/welcome', [
            'consented' => $consent->granted($userId),
        ]);
    }

    /**
     * Explicit consent (Art. 9(2)(a), DPIA §3.2) — a separate, affirmative,
     * informed act, and not a side effect of pressing "start".
     *
     * The nine pairs are the most concentrated profiling this product does, and one
     * of the facets they separate is `spiritual`. A person is entitled to know that
     * before they answer, not after.
     */
    public function consent(Request $request, SetProfilingConsent $consent): RedirectResponse
    {
        $consent->grant((int) $request->user()->id);

        return to_route('calibrate.pair', ['number' => 1]);
    }

    public function pair(Request $request, int $number, CalibrationContent $content, CalibrationProgress $progress, ProfilingConsent $consent): Response|RedirectResponse
    {
        $userId = (int) $request->user()->id;

        // No consent, no profiling. The gate is enforced in the learner too — this is
        // just so nobody is asked nine personal questions whose answers we would then
        // have to throw away.
        if (! $consent->granted($userId)) {
            return to_route('calibrate.welcome');
        }

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

    public function choose(Request $request, int $number, CalibrationContent $content, RecordCalibrationChoice $record, ProfilingConsent $consent): RedirectResponse
    {
        /*
         * The POST needs the gate too, and not just the GET.
         *
         * Gating only the screen left the hole open: the ANSWERS were still written to
         * `profile_signals`. Those answers are not metadata about the profiling — they
         * ARE the sensitive data, the raw "this person chose the chapel over the museum"
         * that the whole Art. 9 problem is made of. Storing them without consent is the
         * same violation as learning from them.
         */
        if (! $consent->granted((int) $request->user()->id)) {
            return to_route('calibrate.welcome');
        }

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
