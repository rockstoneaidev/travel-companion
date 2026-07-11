<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Curation\Actions\GroundCuratedItem;
use App\Domain\Curation\Actions\ReviewCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review gate's UI (CURATION §3 step 4, ADMIN.md pattern): approve turns
 * an LLM draft into Tier-A evidence; nothing unreviewed is served, ever.
 */
final class CurationController extends Controller
{
    public function index(): Response
    {
        $items = CuratedItem::query()
            ->whereIn('status', [CurationStatus::Draft, CurationStatus::NeedsGrounding, CurationStatus::InReview])
            ->leftJoin('places_core', 'places_core.id', '=', 'curated_items.place_id')
            ->orderByRaw("CASE curated_items.status WHEN 'in_review' THEN 0 WHEN 'needs_grounding' THEN 1 ELSE 2 END")
            ->orderBy('curated_items.created_at')
            ->get([
                'curated_items.id', 'curated_items.title', 'curated_items.claim', 'curated_items.facets',
                'curated_items.evidence', 'curated_items.status', 'curated_items.authored_by',
                'curated_items.region_slug', 'places_core.name as place_name',
            ]);

        return Inertia::render('admin/curation', [
            'items' => $items->map(fn (CuratedItem $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'claim' => $item->claim,
                'facets' => $item->facets,
                'evidence' => $item->evidence,
                'status' => $item->status->value,
                'authored_by' => $item->authored_by,
                'region' => $item->region_slug,
                'place_name' => $item->getAttribute('place_name'),
            ])->all(),
            'approvedCount' => CuratedItem::query()->where('status', CurationStatus::Approved)->count(),
        ]);
    }

    public function approve(Request $request, CuratedItem $item, ReviewCuratedItem $review): RedirectResponse
    {
        $validated = $request->validate(['claim' => ['sometimes', 'string', 'max:1000']]);
        $review->approve($item, (int) $request->user()->id, $validated['claim'] ?? null);

        return back();
    }

    public function reject(Request $request, CuratedItem $item, ReviewCuratedItem $review): RedirectResponse
    {
        $review->reject($item, (int) $request->user()->id);

        return back();
    }

    public function ground(CuratedItem $item, GroundCuratedItem $ground): RedirectResponse
    {
        $ground($item);

        return back();
    }
}
