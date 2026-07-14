<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Curation\Data\PackPlan;
use App\Domain\Places\Models\ScoutRun;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Queries\RegionWorldModelStats;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Domain\Sources\Services\RegionCatalog;
use App\Http\Controllers\Controller;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Jobs\Ingest\DraftRegionPackJob;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * World-model ops (ADMIN.md pattern): per-region status and the build button
 * — ingest + resolve run on Horizon, not over SSH. Thin (conventions/04).
 */
final class WorldModelController extends Controller
{
    public function index(ResolveRegion $resolve, RegionWorldModelStats $stats, RegionBuildStatus $builds): Response
    {
        $regions = collect(app(RegionCatalog::class)->all())
            ->map(fn (IngestRegion $region): array => [
                'key' => $region->key,
                'name' => $region->name,
                ...$stats->forRegion($region),
                'unresolved_tiles' => count($resolve->unresolvedTiles($region)),

                // What is happening RIGHT NOW. A button with no feedback is a button
                // people press again — which is exactly what happened.
                'build' => $builds->current($region->key),
                'boxes' => $builds->boxes($region->key),

                // Drafting has to show progress too — a button that looks like it did
                // nothing is a button people press again, and each press costs money.
                'draft' => $builds->currentDraft($region->key),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/world-model', [
            'regions' => $regions,

            // Honestly global, and labelled as such. `scout_runs` has no region column,
            // so a per-region "last scout" would be a number we invented — and this page
            // has done enough of that already.
            'scouts' => [
                'last_run_at' => ScoutRun::query()->latest('created_at')->value('created_at')?->toIso8601String(),
                'hit_rate' => self::hitRate(),
            ],
        ]);
    }

    /** Hit rate across the last 24h of scout runs, or null if nothing ran. */
    private static function hitRate(): ?float
    {
        $row = ScoutRun::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('SUM(tiles_hit) AS hit, SUM(tiles_requested) AS requested')
            ->first();

        $requested = (int) ($row->requested ?? 0);

        return $requested === 0 ? null : round(((int) $row->hit) / $requested, 4);
    }

    /**
     * Draft the region's curation pack (CURATION §4).
     *
     * Deliberate, not a phase of the build: it calls the LLM once per candidate and
     * costs real money, and a "build" button that quietly spends tokens is a bad
     * button. But it was also INVISIBLE — a command you had to know existed and run
     * over SSH — which is why the review queue sat empty for days looking broken.
     */
    public function draft(string $region, RegionBuildStatus $builds): RedirectResponse
    {
        abort_unless(app(RegionCatalog::class)->find($region) !== null, 404);

        $target = PackPlan::targetFor($region);

        // Claim BEFORE dispatching. Double-firing a draft is worse than double-firing a
        // build: each press is N calls to a paid LLM.
        if (! $builds->startDraft($region, $target)) {
            return back()->with('status', "A draft for {$region} is already running.");
        }

        DraftRegionPackJob::dispatch($region, $target);

        return back()->with('status', "Drafting up to {$target} items for {$region}. Nothing is served until you approve it.");
    }

    public function build(string $region, RegionBuildStatus $builds): RedirectResponse
    {
        abort_unless(app(RegionCatalog::class)->find($region) !== null, 404);

        // Claim the region BEFORE dispatching. Pressing the button five times used to
        // queue five builds of the same city — five times the Overpass traffic, on a
        // volunteer service that rate-limits us, to compute an answer we already had.
        if (! $builds->start($region)) {
            return back()->with('status', "A build for {$region} is already running.");
        }

        BuildRegionWorldModelJob::dispatch($region);

        return back()->with('status', "Building {$region} — progress appears on this page.");
    }
}
