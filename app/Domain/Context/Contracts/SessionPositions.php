<?php

declare(strict_types=1);

namespace App\Domain\Context\Contracts;

use App\Domain\Context\Data\PositionFix;

/**
 * "Where is this session, now?" — Context's published answer (conventions/01).
 *
 * Recommendations needs this to decide whether the user has moved far enough to
 * deserve a fresh menu (E46), and it may not reach into Context's tables to find
 * out. The contract exists so the ranking side can ask the question without
 * knowing that the answer is a row in `context_events`.
 *
 * Note what this DOES NOT return: anything inside the user's declared home zone.
 * `RecordContextEvent` nulls the coordinate on the way in and keeps only the H3
 * cell (PRD §16), so a home-zone position is not a position as far as this
 * contract is concerned — and the feed can therefore never re-anchor onto
 * someone's home. That suppression is upstream of here, deliberately: it should
 * be impossible to bypass by adding a new reader.
 */
interface SessionPositions
{
    /**
     * The most recent position reported for this session, or null when there is
     * none fresh enough to act on.
     *
     * @param  int  $maxAgeSeconds  beyond this, a fix is history, not "where they are"
     */
    public function latestFor(string $sessionId, int $maxAgeSeconds): ?PositionFix;
}
