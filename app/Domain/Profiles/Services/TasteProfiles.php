<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Services;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Profiles\Data\TasteProfileData;
use App\Domain\Profiles\Models\UserTasteProfile;

/** Profiles' public API (conventions/01): read taste, apply feedback learning. */
final class TasteProfiles
{
    public function __construct(
        private readonly FacetWeightLearner $learner,
    ) {}

    public function forUser(int $userId): TasteProfileData
    {
        $profile = UserTasteProfile::for($userId);

        return new TasteProfileData(
            facetWeights: array_map(floatval(...), $profile->facet_weights),
            eventCounts: array_map(intval(...), $profile->event_counts),
            walkToleranceMinutes: (int) $profile->walk_tolerance_minutes,
            priceBand: (int) $profile->price_band,
            calibrated: $profile->isCalibrated(),
        );
    }

    /** @param list<string> $facets */
    public function learnFromFeedback(int $userId, FeedbackEvent $event, array $facets): void
    {
        $this->learner->apply(UserTasteProfile::for($userId), $event, $facets);
    }
}
