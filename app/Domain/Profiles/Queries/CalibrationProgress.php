<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Queries;

use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Profiles\Services\CalibrationContent;

/**
 * Where is this person up to? (SCREENS S9: "killing the app mid-flow resumes at
 * the next pair".)
 *
 * A skipped pair counts as ANSWERED. It is not an omission to be re-asked — they
 * were shown it and declined, and asking again would be nagging.
 */
final class CalibrationProgress
{
    public function __construct(private readonly CalibrationContent $content) {}

    /** The next unanswered pair, or null when the pairs are done. */
    public function nextPairNumber(int $userId): ?int
    {
        $answered = ProfileSignal::query()
            ->where('user_id', $userId)
            ->where('calibration_version', CalibrationContent::VERSION)
            ->pluck('pair_number')
            ->all();

        foreach ($this->content->pairs() as $pair) {
            if (! in_array($pair->number, $answered, true)) {
                return $pair->number;
            }
        }

        return null;
    }

    public function isComplete(int $userId): bool
    {
        return UserTasteProfile::query()
            ->where('user_id', $userId)
            ->whereNotNull('calibration_completed_at')
            ->exists();
    }
}
