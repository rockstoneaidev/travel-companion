<?php

declare(strict_types=1);

namespace App\Domain\Trips\Models;

use App\Domain\Trips\Enums\TripSegmentKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a trip, classified (E38). See the migration for why it is stored.
 */
final class TripSegment extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => TripSegmentKind::class,
            'day' => 'date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'confidence' => 'float',
        ];
    }
}
