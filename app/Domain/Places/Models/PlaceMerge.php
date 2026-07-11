<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redirect: a merged-away canonical id → its surviving place
 * (ENTITY-RESOLUTION §2). Never deleted; un-merge adds a new place and leaves
 * the redirect for anything that referenced the old id.
 */
final class PlaceMerge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'merged_at' => 'immutable_datetime',
        ];
    }

    public function canonicalPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'canonical_place_id');
    }
}
