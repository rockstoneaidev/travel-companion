<?php

declare(strict_types=1);

namespace App\Domain\Trips\Models;

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

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'source' => TripSource::class,
            'anchor_point' => AsCoordinates::class,
            'started_at' => 'immutable_datetime',
            'last_session_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ExploreSession, $this> */
    public function exploreSessions(): HasMany
    {
        return $this->hasMany(ExploreSession::class);
    }
}
