<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\InterruptionMetrics;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/interruption (E44; PRD §7.2–§7.3, ADMIN extension).
 *
 * The Phase 2 exit read, made a screen. Thin wrapper (conventions/04): the metrics live in
 * App\Admin\Queries, the exit targets in config/phase.php, and this only joins them.
 */
final class InterruptionController extends Controller
{
    public function index(Request $request, InterruptionMetrics $metrics): Response
    {
        return Inertia::render('admin/interruption', [
            'metrics' => $metrics((string) $request->query('range', '7d')),
            // The exit criteria, set instrument-first (EPICS): the numbers were agreed
            // BEFORE the data existed, so the read is a verdict and not a rationalisation.
            'exitCriteria' => config('phase.exit_criteria'),
        ]);
    }
}
