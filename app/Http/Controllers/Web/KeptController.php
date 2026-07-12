<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\KeptItemResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S6 — KEPT. A thin wrapper over the domain query, like every other Inertia
 * controller here (CLAUDE.md): the "still possible / passed" split is decided in
 * the domain, against the live world model, not in the view.
 */
final class KeptController extends Controller
{
    public function index(Request $request, ListKeptForUser $listKept): Response
    {
        $kept = $listKept->forUser((int) $request->user()->id);

        return Inertia::render('kept', [
            'kept' => KeptItemResource::collection($kept),
        ]);
    }
}
