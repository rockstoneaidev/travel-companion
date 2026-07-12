<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Actions;

use App\Domain\Profiles\Data\CalibrationPair;
use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Profiles\Services\CalibrationContent;
use App\Domain\Profiles\Services\FacetWeightLearner;
use Illuminate\Support\Facades\DB;

/**
 * One answered pair (ONBOARDING §1).
 *
 * The answer is recorded AND applied in one transaction. Recording without
 * applying would leave a profile that disagrees with its own history; applying
 * without recording would make the flow unresumable and the profile
 * unexplainable when the pair set changes.
 *
 * A SKIP applies no update (ONBOARDING §4) — but it is still written, because
 * "shown it and declined" is a different fact from "never shown it", and only the
 * second one means the flow is unfinished.
 */
final class RecordCalibrationChoice
{
    public function __construct(private readonly FacetWeightLearner $learner) {}

    /** @param  'a'|'b'|null  $side  null = skipped */
    public function __invoke(int $userId, CalibrationPair $pair, ?string $side): void
    {
        DB::transaction(function () use ($userId, $pair, $side): void {
            $chosen = $side === null ? [] : $pair->facetsFor($side);
            $rejected = $side === null ? [] : $pair->rejectedFacetsFor($side);

            ProfileSignal::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'calibration_version' => CalibrationContent::VERSION,
                    'pair_number' => $pair->number,
                ],
                [
                    'chosen_side' => $side,
                    'chosen_facets' => $chosen,
                    'rejected_facets' => $rejected,
                ],
            );

            if ($side === null) {
                return;   // a skip teaches nothing (ONBOARDING §4)
            }

            $profile = UserTasteProfile::query()->firstOrCreate(
                ['user_id' => $userId],
                ['profile_model_version' => FacetWeightLearner::VERSION],
            );

            $this->learner->applyCalibrationPair($profile, $chosen, $rejected);
        });
    }
}
