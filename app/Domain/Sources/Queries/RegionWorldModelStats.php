<?php

declare(strict_types=1);

namespace App\Domain\Sources\Queries;

use App\Console\Commands\CurationDraftPackCommand;
use App\Domain\Curation\Services\PackCandidateSelector;
use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Support\Facades\DB;

/**
 * What we actually hold for ONE region (ADMIN.md).
 *
 * This exists because the admin console was showing GLOBAL counts on every region
 * card: Stockholm, Paris, Nantes, Nice and Dijon all reported the same "osm 16 029
 * · 16 963 canonical places", because the query never mentioned the region it was
 * supposedly describing. Every number on that page was true of the database and
 * false of the row it sat in — which is worse than showing nothing, because it
 * looks like an answer.
 *
 * Scoped by the region's bounding box, against the GIST index on `location`.
 */
final class RegionWorldModelStats
{
    public function __construct(private readonly PackCandidateSelector $candidates) {}

    /** @return array<string, mixed> */
    public function forRegion(IngestRegion $region): array
    {
        $envelope = 'ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography';
        $box = [$region->west, $region->south, $region->east, $region->north];

        /** @var array<string, int> $sourceItems */
        $sourceItems = DB::table('source_items')
            ->selectRaw('source, count(*) AS n')
            ->whereRaw("ST_Intersects(location, {$envelope})", $box)
            ->groupBy('source')
            ->pluck('n', 'source')
            ->all();

        $places = DB::table('places_core')
            ->whereRaw("ST_Intersects(location, {$envelope})", $box)
            ->count();

        $curated = DB::table('curated_items')
            ->where('region_slug', $region->key)
            ->selectRaw("count(*) FILTER (WHERE status = 'approved') AS approved,
                         count(*) FILTER (WHERE status = 'in_review') AS in_review")
            ->first();

        return [
            'source_items' => $sourceItems,
            'places' => $places,
            'curated_approved' => (int) ($curated->approved ?? 0),
            // Surfaced because "0 approved" and "0 drafted" are completely different
            // problems and the console showed them as the same number: one means go and
            // review, the other means the pack was never drafted.
            'curated_in_review' => (int) ($curated->in_review ?? 0),

            // How many places have enough evidence to be worth drafting AT ALL.
            //
            // The selector already excludes anything a human has ruled on, so this IS
            // "how many are left" — not "how many exist". Curated 31, left 88 is the
            // pair of numbers that tells you whether you are nearly done or nowhere
            // near, and the page showed neither.
            'pack_candidates' => $candidates = count($this->candidates->forRegion($region->key, 1000)),

            // What a click actually produces — and therefore what it actually costs.
            //
            // The button used to be labelled with `pack_candidates` ("~100 LLM calls"),
            // which was never the number: 100 is just the cap on the candidate QUERY,
            // while a draft run stops at CurationDraftPackCommand::TARGETS (30 for
            // Stockholm). So the one button on the site that spends money overstated
            // its own cost by 3-5x. It is a click, one place at a time, on a paid LLM —
            // that is precisely the number that has to be true.
            'pack_target' => min(CurationDraftPackCommand::TARGETS[$region->key] ?? 20, $candidates),
        ];
    }
}
