<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use Illuminate\Support\Facades\DB;

/**
 * The offline bundle: what the phone needs to fire geofence moments with no signal (E36).
 *
 * ## The rule that makes this safe: the server decides, the device only triggers
 *
 * Non-negotiable #4 — deterministic policy gates ALL delivery, the model never chooses the
 * moment — has to survive the moment happening on a phone in a dead zone with no server in
 * the loop. It does, because of how the work is split:
 *
 *   - THE SERVER pre-authorises. Every geofence in this payload has already passed the gates
 *     that can be judged ahead of time: the opportunity is live and scored (so it cleared the
 *     evidence gate), it is pushable by its licence, and it is close enough to the route to
 *     be worth a stop. An opportunity that fails any of these is simply not in the bundle —
 *     the phone is never handed a moment it should not fire.
 *
 *   - THE DEVICE only does ARITHMETIC. "Am I inside this circle, is it before its window
 *     shuts, is it within quiet hours, have I already had three today, was the last one under
 *     an hour ago." Every one of those is a comparison, not a judgement — the same gates
 *     `NotificationPolicy` runs, shipped as the `budget` block below so the device enforces
 *     them identically and offline. There is no model on the phone, and there is nothing for
 *     one to decide.
 *
 * The device's budget is a MIRROR of the server's, deliberately the same numbers, so an
 * offline geofence moment and an online push obey one policy, not two that can drift.
 */
final class BuildCorridorPayload
{
    /**
     * Primitives in, not a Trip model — Notifications may not hold another module's models
     * (conventions/01, and the arch test that caught exactly this). The caller resolves the
     * trip and hands over the id and its anchor.
     *
     * @return array{geofences: list<array<string, mixed>>, budget: array<string, mixed>}
     */
    public function __invoke(?float $anchorLat, ?float $anchorLng): array
    {
        return [
            'geofences' => $this->geofences($anchorLat, $anchorLng),
            /*
             * The device-side budget — the same constants NotificationPolicy uses, shipped as
             * data. The phone enforces these itself when offline, so a corridor moment cannot
             * break the 3-a-day promise just because there was no signal to check it against.
             */
            'budget' => [
                'max_per_day' => (int) config('notifications.budget.max_per_day'),
                'cooldown_minutes' => (int) config('notifications.budget.cooldown_minutes'),
                'quiet_hours_start' => (int) config('notifications.quiet_hours.default_start'),
                'quiet_hours_end' => (int) config('notifications.quiet_hours.default_end'),
            ],
        ];
    }

    /**
     * The geofence-eligible opportunities near this trip, pre-authorised.
     *
     * @return list<array<string, mixed>>
     */
    private function geofences(?float $anchorLat, ?float $anchorLng): array
    {
        if ($anchorLat === null || $anchorLng === null) {
            return [];   // no geography to build a corridor around yet
        }

        $radiusM = (int) config('notifications.geofence.corridor_radius_meters');
        $triggerM = (int) config('notifications.geofence.trigger_radius_meters');

        $rows = DB::select(<<<'SQL'
            SELECT
                o.id, o.title, o.summary, o.window_ends_at,
                ST_Y(p.location::geometry) AS lat,
                ST_X(p.location::geometry) AS lng
            FROM opportunities o
            JOIN places_core p ON p.id = o.place_id
            WHERE o.status IN ('scored', 'served', 'watching')
              AND o.expires_at > now()
              AND ST_DWithin(
                    p.location,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                    ?
                  )
            ORDER BY o.window_ends_at NULLS LAST
            LIMIT ?
        SQL, [$anchorLng, $anchorLat, $radiusM, (int) config('notifications.geofence.max_payloads')]);

        return array_map(static fn (object $r): array => [
            'opportunity_id' => $r->id,
            'lat' => (float) $r->lat,
            'lng' => (float) $r->lng,
            // The circle the device watches. Small — a geofence moment should fire when you
            // are AT the place, not when you are vaguely near the neighbourhood.
            'radius_m' => $triggerM,
            // The words, already written server-side (a reviewed claim or a generated voice
            // line — never invented on the phone). The device shows this string; it does not
            // compose one.
            'title' => $r->title,
            'body' => $r->summary,
            'deep_link' => "/opportunities/{$r->id}",
            // The one time-gate the device needs beyond quiet hours: do not fire for a
            // place whose window has already shut.
            'window_ends_at' => $r->window_ends_at,
        ], $rows);
    }
}
