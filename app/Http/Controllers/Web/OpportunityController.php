<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\ExplainRecommendation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S4 — opportunity detail (SCREENS.md): the stored recommendation trace is
 * the data; WHY YOU and EVIDENCE come from ExplainRecommendation. Thin
 * (conventions/04).
 */
final class OpportunityController extends Controller
{
    public function show(Request $request, Opportunity $opportunity, ExplainRecommendation $explain): Response
    {
        $recommendation = Recommendation::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('user_id', $request->user()->id)
            ->latest('served_at')
            ->firstOrFail();

        $candidate = $recommendation->score_inputs['candidate'] ?? [];

        return Inertia::render('opportunities/show', [
            'opportunity' => [
                'id' => $opportunity->id,
                'kind' => $opportunity->kind->value,
                'title' => $opportunity->title ?? ($candidate['name'] ?? 'Unnamed place'),
                'summary' => $opportunity->summary,
            ],
            'place' => [
                'name' => $candidate['name'] ?? null,
                'lat' => $candidate['lat'] ?? null,
                'lng' => $candidate['lng'] ?? null,
                'type' => $candidate['type'] ?? null,
                'facets' => $candidate['facets'] ?? [],
            ],
            'recommendation' => [
                'id' => $recommendation->id,
                'walk_minutes' => $recommendation->score_inputs['reachability']['travel_min'] ?? null,
            ],
            'explanation' => $explain($recommendation),
            'sessionId' => $recommendation->explore_session_id,
        ]);
    }
}
