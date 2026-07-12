<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\ScoutRun;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\SourceItem;
use App\Http\Controllers\Controller;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * World-model ops (ADMIN.md pattern): per-region status and the build button
 * — ingest + resolve run on Horizon, not over SSH. Thin (conventions/04).
 */
final class WorldModelController extends Controller
{
    public function index(ResolveRegion $resolve): Response
    {
        $regions = collect(IngestRegion::all())->map(function (IngestRegion $region) use ($resolve): array {
            $sourceCounts = SourceItem::query()
                ->selectRaw('source, count(*) AS n')
                ->groupBy('source')
                ->pluck('n', 'source');

            return [
                'key' => $region->key,
                'name' => $region->name,
                'source_items' => $sourceCounts->all(),
                'places' => Place::query()->count(),
                'unresolved_tiles' => count($resolve->unresolvedTiles($region)),
                'approved_curated' => CuratedItem::query()->where('status', CurationStatus::Approved)->count(),
                'last_scout_run' => ScoutRun::query()->latest('created_at')->value('created_at')?->toIso8601String(),
            ];
        })->values()->all();

        return Inertia::render('admin/world-model', ['regions' => $regions]);
    }

    public function build(string $region): RedirectResponse
    {
        abort_unless(array_key_exists($region, IngestRegion::all()), 404);

        BuildRegionWorldModelJob::dispatch($region);

        return back()->with('status', 'Build queued — watch progress in Horizon.');
    }
}
