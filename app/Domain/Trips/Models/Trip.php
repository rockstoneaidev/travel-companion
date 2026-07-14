<?php

declare(strict_types=1);

namespace App\Domain\Trips\Models;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Casts\AsCoordinates;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Policies\TripPolicy;
use Database\Factories\Domain\Trips\TripFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The implicit-first container (PRD §6.6). Persistence and relationships only —
 * the clustering rule lives in ResolveOrCreateTripForSession (conventions/01).
 */
#[UseFactory(TripFactory::class)]
#[UsePolicy(TripPolicy::class)]
final class Trip extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    /** Real until something with `location_emulate` says otherwise (ADMIN §6). */
    protected $attributes = ['context_source' => 'device'];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            // WHEN they agreed to be followed, and when they stopped (E29, PRD §16).
            'trip_mode_started_at' => 'immutable_datetime',
            'trip_mode_ended_at' => 'immutable_datetime',
            'context_source' => ContextSource::class,
            'source' => TripSource::class,
            'anchor_point' => AsCoordinates::class,
            'started_at' => 'immutable_datetime',
            'last_session_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * Is the companion switched on for this trip? (PRD §8.2, §16.)
     *
     * Started and not stopped. Not a boolean column, because "are we allowed to follow
     * this person" and "when did they say so" are the same question asked twice, and only
     * one of those answers survives an audit.
     */
    public function inTripMode(): bool
    {
        return $this->trip_mode_started_at !== null && $this->trip_mode_ended_at === null;
    }

    /** @return HasMany<ExploreSession, $this> */
    public function exploreSessions(): HasMany
    {
        return $this->hasMany(ExploreSession::class);
    }
}
