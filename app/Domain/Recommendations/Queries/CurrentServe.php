<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Recommendations\Data\ServeData;
use App\Domain\Recommendations\Models\Recommendation;

/**
 * The batch identity of the feed currently on screen (E46).
 *
 * Read AFTER `RankSession::feedFor()` has run, never before: feedFor is what decides
 * whether this pull re-anchors, and asking first would describe the batch the user is
 * being moved away from.
 */
final class CurrentServe
{
    public function for(string $sessionId): ?ServeData
    {
        /*
         * The row that OPENED the batch — lowest position in the highest group.
         *
         * Not just "any row in the group": a backfill appends rows carrying
         * `dismiss_backfill`, and reading the reason off one of those would tell the
         * client the menu had been topped up when what actually happened is that the
         * user walked across a river. The reason a batch exists is the reason its
         * first card was served.
         */
        $opening = Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->orderByDesc('serve_group')
            ->orderBy('position')
            ->first();

        if ($opening === null) {
            return null;
        }

        return new ServeData(
            group: $opening->serve_group,
            reason: $opening->serve_reason,
            anchor: $opening->anchor,
            servedAt: $opening->served_at,
        );
    }
}
