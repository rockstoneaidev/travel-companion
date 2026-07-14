<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Context\Actions\RecordContextEvent;
use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Queries\TileBoundaries;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\SessionPipelineLog;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Actions\EndExploreSession;
use App\Domain\Trips\Actions\StartExploreSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Emulator\MovePinRequest;
use App\Http\Requests\Admin\Emulator\StartEmulationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/admin/emulator` — the glass cockpit (ADMIN §6; E47).
 *
 * "The single most valuable dev tool in the console, and one design decision makes or
 * breaks it: **the emulated position enters through the same context-ingestion boundary
 * as a real one**." That decision is honoured literally here — a tick of the pin calls
 * `RecordContextEvent`, the same action the phone's `POST /explore/{s}/context-events`
 * calls, and nothing downstream can tell the difference. It cannot: we are testing the
 * actual pipeline, not a lookalike.
 *
 * What separates the simulation from reality is a single flag on the session
 * (`context_source`), and everything that must not learn from it reads that flag. See
 * `tests/Feature/Admin/EmulatedContextTest.php` — ADMIN §14 calls it "a CLAUDE.md-grade
 * invariant for this console", and it is the reason this tool is safe to point at
 * production data.
 */
final class EmulatorController extends Controller
{
    public function index(
        Request $request,
        CoverageGeometry $geometry,
        TileBoundaries $boundaries,
        SessionPipelineLog $log,
    ): Response {
        $session = $this->activeEmulation((int) $request->user()->id);

        return Inertia::render('admin/emulator', [
            'emulation' => $session === null ? null : [
                'id' => $session->id,
                'origin' => $session->origin?->toArray(),
                'travel_mode' => $session->travel_mode->value,
                'time_budget_minutes' => $session->time_budget_minutes,
                'heading' => $session->heading,
                'destination_point' => $session->destination_point?->toArray(),
                'started_at' => $session->started_at->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ],

            // The cone, re-aimed. Recomputed on every poll because it is a handful of
            // H3 calls and a single SQL statement — cheap enough to always be true.
            'coverage' => $session === null ? null : $this->coverage($session, $geometry, $boundaries),

            // What the pipeline ACTUALLY served here — read from the stored trace, never
            // re-ranked. A cockpit that re-ran the engine every time you looked at the
            // dials would be a cockpit that flies the plane.
            'served' => $session === null ? [] : $this->served($session->id),

            'log' => $session === null ? [] : $log->forSession($session->id),

            'controls' => [
                'travel_modes' => TravelMode::options(),
                'speeds_kmh' => array_map(
                    static fn (array $mode): float => (float) $mode['speed_kmh'],
                    (array) config('tiles.modes'),
                ),
                'feed_size' => (int) config('trips.session.feed_size'),
                'min_drift_meters' => (int) config('trips.reanchor.min_drift_meters'),
                'min_interval_seconds' => (int) config('trips.reanchor.min_interval_seconds'),
            ],
        ]);
    }

    /** Drop the pin: start an emulated session (ADMIN §6 — audit-logged, always). */
    public function store(StartEmulationRequest $request, StartExploreSession $start): RedirectResponse
    {
        $session = $start($request->toData());

        /*
         * Audit-logged, always (ADMIN §6) — and via `withProperties`, not `performedOn`.
         *
         * spatie's `activity_log.subject_id` is a bigint, and an explore session has a
         * UUID key, so pointing the subject at it throws. The session id is what an
         * auditor actually needs ("which emulation was this?"), so it goes in the
         * properties where it fits, rather than the schema being bent to hold it.
         */
        activity()
            ->causedBy($request->user())
            ->withProperties([
                'session_id' => $session->id,
                'origin' => $session->origin?->toArray(),
                'travel_mode' => $session->travel_mode->value,
                'time_budget_minutes' => $session->time_budget_minutes,
            ])
            ->log('emulation.started');

        return to_route('admin.emulator.index');
    }

    /**
     * One tick of playback — a real context event, through the real boundary.
     *
     * Returns 204 rather than a redirect: at 60× a walk fires these several times a
     * second, and turning each into an Inertia navigation would make the map fight the
     * router. The page polls for the consequences separately.
     */
    public function move(MovePinRequest $request, RecordContextEvent $record): JsonResponse
    {
        $session = $this->guardOwnEmulation($request, (string) $request->input('session_id'));

        $record($request->toData());

        return new JsonResponse(['session_id' => $session->id], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * "What WOULD this position serve?" — `RankSession::plan()`, the pure pass (PRD §15.2).
     *
     * Read-only: it warms the shared tile cache and writes no recommendations, so it
     * spends no serve budget and leaves no trace. It is the honest way to ask the
     * question when you do not want the answer to become a fact.
     */
    public function dryRun(Request $request, RankSession $rank): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $session = $this->guardOwnEmulation($request, (string) $validated['session_id']);

        $data = ExploreSessionData::fromModel($session)->reAnchoredAt(
            new Coordinates((float) $validated['lat'], (float) $validated['lng']),
        );

        $plan = $rank->plan($data);

        return new JsonResponse([
            'picked' => array_map($this->candidateLine(...), $plan['picked']),
            'held' => array_map(
                fn (array $c): array => [
                    ...$this->candidateLine($c),
                    'hold' => $c['hold']['reason'] ?? 'held',
                ],
                $plan['held'],
            ),
            'funnel' => [
                'unreachable' => $plan['unreachable']['count'],
                'held' => count($plan['held']),
                'near_misses' => count($plan['near_misses']),
                'served' => count($plan['picked']),
            ],
            'scout_summary' => $plan['scout_summary'],
            'rank_ms' => $plan['rank_ms'],
        ]);
    }

    public function destroy(Request $request, EndExploreSession $end): RedirectResponse
    {
        $session = $this->activeEmulation((int) $request->user()->id);

        if ($session !== null) {
            $end($session);

            activity()
                ->causedBy($request->user())
                ->withProperties(['session_id' => $session->id])
                ->log('emulation.stopped');
        }

        return to_route('admin.emulator.index');
    }

    /**
     * The operator's own live emulation, or nothing.
     *
     * Scoped to the operator, always: two superadmins debugging at once must not drive
     * each other's pin, and "the emulation" is not a global.
     */
    private function activeEmulation(int $userId): ?ExploreSession
    {
        return ExploreSession::query()
            ->where('user_id', $userId)
            ->where('context_source', ContextSource::Emulated)
            ->where('status', ExploreSessionStatus::Active)
            ->latest('started_at')
            ->first();
    }

    /** You may only drive a pin that is yours, and that is actually a pin. */
    private function guardOwnEmulation(Request $request, string $sessionId): ExploreSession
    {
        $session = ExploreSession::query()->findOrFail($sessionId);

        // Not a 403 by accident: without this, the emulator would be a hand-rolled API
        // for writing `emulated` context events onto ANY session — including a real
        // traveller's, which is precisely the contamination the flag exists to prevent.
        abort_unless(
            (int) $session->user_id === (int) $request->user()->id
                && $session->context_source === ContextSource::Emulated,
            403,
        );

        return $session;
    }

    /** @return array<string, mixed> */
    private function coverage(ExploreSession $session, CoverageGeometry $geometry, TileBoundaries $boundaries): array
    {
        if ($session->origin === null) {
            return ['cells' => [], 'origin_cell' => null];
        }

        $coverage = $geometry->forSession(
            $session->origin->lat,
            $session->origin->lng,
            $session->travel_mode,
            $session->time_budget_minutes,
            $session->heading,
            $session->destination_point?->lat,
            $session->destination_point?->lng,
        );

        $near = $coverage->nearTiles;
        $polygons = $boundaries->forCells($coverage->allTiles());

        $cells = [];

        foreach ($polygons as $cell => $polygon) {
            $cells[] = [
                'cell' => $cell,
                // Near vs far is not decoration: it is which scouts run out there
                // (conventions/12 — "a café is worth a 300 m detour, a ruined castle 20 km").
                'range' => in_array($cell, $near, true) ? 'near' : 'far',
                'geometry' => $polygon,
            ];
        }

        return [
            'cells' => $cells,
            'origin_cell' => $coverage->originCell,
            'mode' => $coverage->mode,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function served(string $sessionId): array
    {
        $group = (int) Recommendation::query()->where('explore_session_id', $sessionId)->max('serve_group');

        if ($group === 0) {
            return [];
        }

        return Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->where('serve_group', $group)
            ->orderBy('position')
            ->get()
            ->map(fn (Recommendation $r): array => [
                'id' => $r->id,
                'position' => $r->position,
                'name' => $r->score_inputs['candidate']['name'] ?? 'unknown',
                'lat' => $r->score_inputs['candidate']['lat'] ?? null,
                'lng' => $r->score_inputs['candidate']['lng'] ?? null,
                'composite' => $r->scores['composite'] ?? null,
                'serve_reason' => $r->serve_reason->value,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function candidateLine(array $candidate): array
    {
        return [
            'place_id' => $candidate['place_id'],
            'name' => $candidate['name'],
            'lat' => $candidate['lat'] ?? null,
            'lng' => $candidate['lng'] ?? null,
            'composite' => $candidate['composite'] ?? null,
            'travel_min' => $candidate['reachability']['travel_min'] ?? null,
        ];
    }
}
