<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Trips\Queries\BuildJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** S7 — JOURNAL. Thin wrapper over the domain query (conventions/04). */
final class JournalController extends Controller
{
    public function index(Request $request, BuildJournal $journal): Response
    {
        return Inertia::render('journal', [
            'trips' => $journal->forUser((int) $request->user()->id),
        ]);
    }
}
