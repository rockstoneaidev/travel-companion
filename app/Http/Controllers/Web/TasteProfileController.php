<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Profiles\Actions\ResetTasteProfile;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\CompositeScorer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Taste (SCREENS S10). Thin wrapper over the domain action.
 *
 * The screen shows what we think we know, in plain words, before offering to
 * throw it away — "reset my taste profile" is not a button you should have to
 * press blind.
 */
final class TasteProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = UserTasteProfile::for((int) $request->user()->id);
        $scorer = new CompositeScorer(ScoringModel::v1());

        $weights = $profile->facet_weights;
        arsort($weights);

        return Inertia::render('settings/taste', [
            'taste' => [
                'calibrated' => $profile->isCalibrated(),
                // α is the honest headline: how much of your ranking is actually
                // YOU yet, as opposed to the cold-start average (SCORING §6).
                'alpha' => $scorer->alpha($profile->event_counts, calibrated: $profile->isCalibrated()),
                'leans_toward' => array_slice(array_keys(array_filter($weights, fn (float $w): bool => $w > 0.5)), 0, 5),
                'leans_away' => array_slice(array_keys(array_filter($weights, fn (float $w): bool => $w < 0.5)), -3),
                'walk_tolerance_minutes' => $profile->walk_tolerance_minutes,
                'price_band' => $profile->price_band,
            ],
        ]);
    }

    public function destroy(Request $request, ResetTasteProfile $reset): RedirectResponse
    {
        $reset((int) $request->user()->id);

        // Straight back into calibration: a reset profile with no offer to rebuild
        // it is a user left worse off than when they arrived.
        return to_route('calibrate.welcome');
    }
}
