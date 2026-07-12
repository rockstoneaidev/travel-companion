<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Actions;

use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Profiles\Services\CalibrationContent;
use App\Domain\Profiles\Services\FacetWeightLearner;
use Illuminate\Support\Facades\DB;

/**
 * The two practical answers, and the end of the flow (ONBOARDING §3).
 *
 * This is the step that actually changes what a person is shown. Stamping
 * `calibration_completed_at` sets **α₀ = 0.4** (SCORING §6): until it lands, α is
 * 0 and every user gets the pure cold vector — uniqueness-heavy, personal_fit
 * weighted at 0.06. Afterwards their own answers carry a third of the ranking.
 *
 * The practicals seed FRICTION, not taste. How far you will walk is not something
 * you like, and putting it in the facet weights would be a category error that
 * quietly corrupts every recommendation.
 *
 * Skipping everything is allowed and means something: no signals, no completion
 * stamp, α stays 0, and the person gets honest cold-start ranking rather than a
 * confident guess built on nothing (ONBOARDING §4).
 */
final class CompleteCalibration
{
    public function __invoke(int $userId, ?int $walkMinutes, ?int $priceBand): void
    {
        DB::transaction(function () use ($userId, $walkMinutes, $priceBand): void {
            $profile = UserTasteProfile::query()->firstOrCreate(
                ['user_id' => $userId],
                ['profile_model_version' => FacetWeightLearner::VERSION],
            );

            $answeredAnything = ProfileSignal::query()
                ->where('user_id', $userId)
                ->where('calibration_version', CalibrationContent::VERSION)
                ->whereNotNull('chosen_side')
                ->exists();

            $attributes = [];

            // Defaults stand if skipped (walk 15, price band 2 — the column defaults).
            if ($walkMinutes !== null) {
                $attributes['walk_tolerance_minutes'] = $walkMinutes;
            }

            if ($priceBand !== null) {
                $attributes['price_band'] = $priceBand;
            }

            // α₀ = 0.4 is earned, not granted. A user who skipped every pair has
            // told us nothing, and pretending otherwise would weight a taste vector
            // that is still entirely the 0.5 default.
            if ($answeredAnything) {
                $attributes['calibration_completed_at'] = now();
            }

            if ($attributes !== []) {
                $profile->forceFill($attributes)->save();
            }
        });
    }
}
