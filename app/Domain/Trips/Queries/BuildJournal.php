<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Places\Contracts\PlaceImageLookup;
use Illuminate\Support\Facades\DB;

/**
 * JOURNAL (SCREENS S7) — the seed of "your travel memory belongs to you".
 *
 * Trips, newest first, each with the things the user actually DID: the places they
 * confirmed they visited, and the ones they said "take me" to. Phase 1 keeps it
 * thin on purpose — a list, not a scrapbook.
 *
 * Built from the feedback ledger rather than from location history, and that is
 * the whole point: the journal survives trip-level location deletion (PRD §16).
 * You can erase where you WERE and still keep what you DID, which is the version
 * of "your memory belongs to you" that means something.
 */
final class BuildJournal
{
    public function __construct(private readonly PlaceImageLookup $images) {}

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        $trips = DB::table('trips')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'created_at']);

        if ($trips->isEmpty()) {
            return [];
        }

        $entries = DB::table('recommendation_feedback as f')
            ->join('recommendations as r', 'r.id', '=', 'f.recommendation_id')
            ->where('r.user_id', $userId)
            ->whereIn('f.event', [FeedbackEvent::Visited->value, FeedbackEvent::Accepted->value])
            ->orderByDesc('f.occurred_at')
            ->get(['r.trip_id', 'r.score_inputs', 'f.event', 'f.occurred_at']);

        $images = $this->images->forPlaces(
            $entries
                ->map(static fn ($e) => json_decode((string) $e->score_inputs, true)['candidate']['place_id'] ?? null)
                ->filter()->unique()->values()->all(),
        );

        $byTrip = [];

        foreach ($entries as $entry) {
            $candidate = json_decode((string) $entry->score_inputs, true)['candidate'] ?? [];
            $name = $candidate['name'] ?? null;

            if ($name === null) {
                continue;
            }

            // "I was here" outranks "I set off for here": if both exist for a place,
            // the confirmed visit is the truer memory and the one worth keeping.
            $key = $entry->trip_id.'|'.$name;

            if (isset($byTrip[$key]) && $byTrip[$key]['visited']) {
                continue;
            }

            $byTrip[$key] = [
                'trip_id' => $entry->trip_id,
                'title' => $name,
                'visited' => $entry->event === FeedbackEvent::Visited->value,
                'occurred_at' => $entry->occurred_at,
                'image' => $images[$candidate['place_id'] ?? ''] ?? null,
            ];
        }

        $weather = $this->weatherByTrip($trips->pluck('id')->all());

        return $trips->map(fn (object $trip): array => [
            'id' => $trip->id,
            'name' => $trip->name,
            'started_at' => $trip->created_at,
            'weather' => $weather[$trip->id] ?? null,
            'entries' => array_values(array_filter(
                $byTrip,
                static fn (array $e): bool => $e['trip_id'] === $trip->id,
            )),
        ])->all();
    }

    /**
     * What the sky was doing while you were there.
     *
     * The observations are the ones we actually SAW — snapshotted on each session at the
     * moment we ranked under them — not a lookup after the fact. Open-Meteo's forecast
     * endpoint cannot tell you about last August, and the LLM is never a source of facts
     * (non-negotiable #3), so an observation not written down at the time is gone for good.
     *
     * Reported as a range plus a wet-day count rather than an average, because an average
     * is the one summary that can be true of a week nobody experienced: 18°C every day and
     * "8°C then 28°C" have the same mean and nothing else in common. A range is a memory;
     * a mean is a statistic.
     *
     * `null` when we never knew — which is NOT the same as "it was dry", and is exactly
     * the distinction `weather_c: 0` on the decision trace was unable to make.
     *
     * @param  list<string>  $tripIds
     * @return array<string, array<string, mixed>>
     */
    private function weatherByTrip(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = DB::table('explore_sessions')
            ->whereIn('trip_id', $tripIds)
            ->whereNotNull('weather')
            ->groupBy('trip_id')
            ->selectRaw('trip_id')
            ->selectRaw("MIN((weather->>'temp_c')::float) AS min_c")
            ->selectRaw("MAX((weather->>'temp_c')::float) AS max_c")
            ->selectRaw("COUNT(*) FILTER (WHERE (weather->>'precip_mm')::float >= 0.2) AS wet")
            ->selectRaw('COUNT(*) AS observations')
            ->get();

        $byTrip = [];

        foreach ($rows as $row) {
            $byTrip[$row->trip_id] = [
                'min_c' => $row->min_c === null ? null : round((float) $row->min_c),
                'max_c' => $row->max_c === null ? null : round((float) $row->max_c),
                'wet_observations' => (int) $row->wet,
                'observations' => (int) $row->observations,
            ];
        }

        return $byTrip;
    }
}
