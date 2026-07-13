<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Recommendations\Queries\ListDismissedForUser;
use App\Domain\Recommendations\Queries\ListKeptForUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DismissedItemResource;
use App\Http\Resources\Api\V1\KeptItemResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S6 — KEPT. A thin wrapper over the domain queries, like every other Inertia
 * controller here (CLAUDE.md): the "still possible / passed" split is decided in
 * the domain, against the live world model, not in the view.
 *
 * The dismissed list rides along on the same screen because it is the same
 * question asked with the opposite sign — "what have I told you about the things
 * you showed me" — and because a way to undo "Not for me" that lives on its own
 * screen behind its own nav item is a way to undo it that nobody ever finds.
 */
final class KeptController extends Controller
{
    public function index(Request $request, ListKeptForUser $listKept, ListDismissedForUser $listDismissed): Response
    {
        $userId = (int) $request->user()->id;

        return Inertia::render('kept', [
            'kept' => KeptItemResource::collection($listKept->forUser($userId)),
            'dismissed' => DismissedItemResource::collection($listDismissed->forUser($userId)),
        ]);
    }
}
