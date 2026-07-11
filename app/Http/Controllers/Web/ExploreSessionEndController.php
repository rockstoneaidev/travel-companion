<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Trips\Actions\EndExploreSession;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class ExploreSessionEndController extends Controller
{
    public function store(ExploreSession $exploreSession, EndExploreSession $endExploreSession): RedirectResponse
    {
        $endExploreSession($exploreSession);

        return to_route('explore.show', $exploreSession)->with('status', 'explore-session-ended');
    }
}
