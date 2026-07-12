<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Privacy\Actions\DeleteAccount;
use App\Domain\Privacy\Actions\ExportUserData;
use App\Domain\Privacy\Actions\SetProfilingConsent;
use App\Domain\Privacy\Actions\UpdatePrivacySettings;
use App\Domain\Privacy\Queries\PrivacySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Privacy (PRD §16, SCREENS S10). A thin wrapper: the policy lives in
 * the domain actions.
 */
final class PrivacyController extends Controller
{
    public function edit(Request $request, PrivacySettings $settings): Response
    {
        return Inertia::render('settings/privacy', [
            'privacy' => $settings->forUser((int) $request->user()->id),
        ]);
    }

    /** Declare (or move) the home zone. */
    public function updateHomeZone(Request $request, UpdatePrivacySettings $update): RedirectResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => [
                'required', 'integer',
                'min:'.config('privacy.home_zone.min_radius_meters'),
                'max:'.config('privacy.home_zone.max_radius_meters'),
            ],
        ]);

        $update->declareHomeZone(
            (int) $request->user()->id,
            (float) $validated['lat'],
            (float) $validated['lng'],
            (int) $validated['radius_meters'],
        );

        return back();
    }

    public function forgetHomeZone(Request $request, UpdatePrivacySettings $update): RedirectResponse
    {
        $update->forgetHomeZone((int) $request->user()->id);

        return back();
    }

    /**
     * Give or take back consent to be profiled (Art. 9(2)(a), Art. 7(3)).
     *
     * Withdrawal DELETES the profile, and that is a legal reading rather than a UX
     * flourish: holding a vector from which someone's religious belief can be deduced
     * is itself processing, so "stop learning but keep what you inferred" would leave
     * us storing Art. 9 data with no basis at all — worse than never having asked.
     */
    public function updateProfilingConsent(Request $request, SetProfilingConsent $consent): RedirectResponse
    {
        $validated = $request->validate(['consent' => ['required', 'boolean']]);

        $userId = (int) $request->user()->id;

        $validated['consent'] ? $consent->grant($userId) : $consent->withdraw($userId);

        return back();
    }

    public function updateResearchConsent(Request $request, UpdatePrivacySettings $update): RedirectResponse
    {
        $validated = $request->validate(['research_consent' => ['required', 'boolean']]);

        $update->setResearchConsent((int) $request->user()->id, (bool) $validated['research_consent']);

        return back();
    }

    /** Data portability (Art. 20) — a file they can actually keep. */
    public function export(Request $request, ExportUserData $export): JsonResponse
    {
        $data = $export((int) $request->user()->id);

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="travel-companion-export.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Erasure (Art. 17). Password-confirmed, because it cannot be undone. */
    public function destroy(Request $request, DeleteAccount $delete): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        Auth::logout();
        $delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
