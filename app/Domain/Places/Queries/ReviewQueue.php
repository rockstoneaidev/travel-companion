<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use App\Domain\Places\Data\ReviewPairData;
use App\Domain\Places\Enums\MatchBand;
use Illuminate\Support\Facades\DB;

/**
 * The entity-resolution review queue (ENTITY-RESOLUTION §3 stage 4, §6).
 *
 * A review-band item is resolved as its own place *and* keeps a decision row
 * naming the place it was compared to. Both are live in the meantime — nothing
 * is lost — but until a human looks, the world model quietly holds a probable
 * duplicate. This is the query that makes them reachable.
 *
 * `decided_by = 'auto'` is the queue; a human decision stamps it and it leaves.
 */
final class ReviewQueue
{
    /** @return list<ReviewPairData> */
    public function pending(int $limit = 50): array
    {
        $rows = DB::table('place_match_decisions as d')
            ->join('source_items as si', 'si.id', '=', 'd.source_item_id')
            // The place the reviewed item itself became.
            ->join('place_source_ids as psi', function ($join): void {
                $join->on('psi.source', '=', 'si.source')
                    ->on('psi.external_id', '=', 'si.external_id');
            })
            ->join('places_core as candidate', 'candidate.id', '=', 'psi.place_id')
            // The place it was compared against.
            ->join('places_core as compared', 'compared.id', '=', 'd.place_id')
            ->where('d.band', MatchBand::Review->value)
            ->where('d.decided_by', 'auto')
            ->where('d.resolver_version', (string) config('resolver.version'))
            ->whereColumn('candidate.id', '!=', 'compared.id')
            ->orderByDesc('d.score')
            ->orderBy('d.id')
            ->limit($limit)
            ->get([
                'd.id as decision_id', 'd.score', 'd.signals',
                'candidate.id as candidate_id', 'candidate.name as candidate_name', 'candidate.source as candidate_source',
                'compared.id as compared_id', 'compared.name as compared_name', 'compared.source as compared_source',
                DB::raw('ST_Distance(candidate.location, compared.location) as distance_meters'),
            ]);

        return $rows->map(fn (object $row): ReviewPairData => new ReviewPairData(
            decisionId: (string) $row->decision_id,
            candidatePlaceId: (string) $row->candidate_id,
            candidatePlaceName: (string) $row->candidate_name,
            candidateSource: (string) $row->candidate_source,
            comparedPlaceId: (string) $row->compared_id,
            comparedPlaceName: (string) $row->compared_name,
            comparedSource: (string) $row->compared_source,
            score: $row->score === null ? null : (float) $row->score,
            distanceMeters: $row->distance_meters === null ? null : (int) round((float) $row->distance_meters),
            signals: json_decode((string) $row->signals, true) ?: [],
        ))->all();
    }

    public function pendingCount(): int
    {
        return DB::table('place_match_decisions')
            ->where('band', MatchBand::Review->value)
            ->where('decided_by', 'auto')
            ->where('resolver_version', (string) config('resolver.version'))
            ->count();
    }
}
