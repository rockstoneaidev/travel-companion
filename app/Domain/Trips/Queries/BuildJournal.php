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

        return $trips->map(fn (object $trip): array => [
            'id' => $trip->id,
            'name' => $trip->name,
            'started_at' => $trip->created_at,
            'entries' => array_values(array_filter(
                $byTrip,
                static fn (array $e): bool => $e['trip_id'] === $trip->id,
            )),
        ])->all();
    }
}
