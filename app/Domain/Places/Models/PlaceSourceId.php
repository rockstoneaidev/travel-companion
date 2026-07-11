<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cross-source ID concordance row (GEO-CORE ZONE). For Google, only the
 * external place_id string is ever stored — nothing else (conventions/03).
 */
final class PlaceSourceId extends Model
{
    protected $guarded = [];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
