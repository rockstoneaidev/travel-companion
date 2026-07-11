<?php

declare(strict_types=1);

namespace App\Domain\Trips\Models;

use App\Domain\Places\Casts\AsCoordinates;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Policies\ExploreSessionPolicy;
use Database\Factories\Domain\Trips\ExploreSessionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The atomic Phase 1 unit (PRD §6.6). Never call this "session" in a table or a
 * variable that Laravel could mistake for its own session store.
 *
 * UUID primary key, ordered (HasUuids emits UUIDv7): sessions are read in
 * insert order, so index locality matters (conventions/03).
 */
#[UseFactory(ExploreSessionFactory::class)]
#[UsePolicy(ExploreSessionPolicy::class)]
final class ExploreSession extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ExploreSessionStatus::class,
            'travel_mode' => TravelMode::class,
            'origin' => AsCoordinates::class,
            'destination_point' => AsCoordinates::class,
            'time_budget_minutes' => 'integer',
            'heading' => 'integer',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
