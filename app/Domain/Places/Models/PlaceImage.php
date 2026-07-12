<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use Illuminate\Database\Eloquent\Model;

/** One Commons image for a place, carrying its own license (per-file — DATA-SOURCES §2). */
final class PlaceImage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retrieved_at' => 'immutable_datetime',
        ];
    }
}
