<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Queries\NearbyEssentials;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "I need a…" — the nearest toilets, pharmacies, chargers, shelter and transport, by pure
 * distance from where the traveller is right now. JSON, not a page visit: the essentials sheet
 * opens over whatever screen they are on, the way a real emergency does not wait for a
 * navigation.
 *
 * Location comes from the client (it already has the fix it uses to re-anchor the feed), so
 * this needs no session — you might need a toilet before you have started exploring anything.
 */
final class EssentialsController extends Controller
{
    public function index(Request $request, NearbyEssentials $essentials): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return new JsonResponse([
            'data' => $essentials->near(new Coordinates((float) $data['lat'], (float) $data['lng'])),
        ]);
    }
}
