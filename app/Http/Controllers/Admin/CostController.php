<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Cost\Queries\CostExplorer;
use App\Cost\Services\SpendGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /admin/costs (docs/COST.md §7.3–§7.4, ADMIN §7.1).
 *
 * A thin wrapper, as controllers are (conventions/04): the drill-down lives in
 * App\Cost\Queries, the switch in App\Cost\Services.
 */
final class CostController extends Controller
{
    public function index(Request $request, CostExplorer $explorer): Response
    {
        $filters = $request->only([
            'category', 'vendor', 'resource', 'actor_kind', 'model',
            'prompt_version', 'region_key', 'user_id',
        ]);

        return Inertia::render('admin/costs', [
            'data' => $explorer((string) $request->query('range', '7d'), array_filter($filters)),
            'controls' => $explorer->controls(),
        ]);
    }

    /**
     * Accounting wants files, not screenshots.
     *
     * Streamed rather than built in memory: an export is exactly the request that will
     * one day be run over a year of a busy month, and a cost tool that OOMs the app it
     * is measuring would be a poor joke.
     */
    public function export(Request $request, CostExplorer $explorer): StreamedResponse
    {
        $filters = array_filter($request->only([
            'category', 'vendor', 'resource', 'actor_kind', 'model',
            'prompt_version', 'region_key', 'user_id',
        ]));

        $rows = $explorer((string) $request->query('range', '7d'), $filters)['events'];

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'occurred_at', 'actor_kind', 'category', 'vendor', 'resource', 'model',
                'prompt_version', 'user_id', 'region_key', 'input_tokens', 'output_tokens',
                'calls', 'billed_usd', 'would_have_billed_usd', 'cached', 'price_version',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['occurredAt'], $row['actorKind'], $row['category'], $row['vendor'],
                    $row['resource'], $row['model'], $row['promptVersion'], $row['userId'],
                    $row['regionKey'], $row['inputTokens'], $row['outputTokens'], $row['calls'],
                    // USD in the file, micros in the database: a spreadsheet is read by a
                    // human, and the ledger is read by code.
                    number_format($row['micros'] / 1_000_000, 6, '.', ''),
                    number_format($row['wouldHaveMicros'] / 1_000_000, 6, '.', ''),
                    $row['cached'] ? 'yes' : 'no',
                    $row['priceVersion'],
                ]);
            }

            fclose($out);
        }, 'cost-events-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The manual switch (COST.md §7.4).
     *
     * Superadmin-only and audit-logged, because it is a lever that makes the product
     * quieter for everyone. Note what is NOT here: the cap VALUES. Changing what "too
     * much" means should be a reviewed config change, not a slider someone drags at 2am;
     * stopping the bleeding should not need a deploy. Different questions, different
     * mechanisms.
     */
    public function pause(Request $request, SpendGuard $guard): RedirectResponse
    {
        $resume = $request->boolean('resume');

        $resume ? $guard->resume() : $guard->pause();

        activity()
            ->causedBy($request->user())
            ->withProperties(['resumed' => $resume])
            ->log($resume ? 'resumed paid calls' : 'paused all paid calls');

        return back();
    }
}
