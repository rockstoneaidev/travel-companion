<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use App\Domain\Places\Enums\MatchBand;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resolver decision: source item → place (or review/distinct), with the
 * per-signal evidence recorded (ENTITY-RESOLUTION §2). Append-only audit.
 */
final class PlaceMatchDecision extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'band' => MatchBand::class,
            'signals' => 'array',
        ];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
