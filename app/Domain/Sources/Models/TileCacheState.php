<?php

declare(strict_types=1);

namespace App\Domain\Sources\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-(tile, source) scout bookkeeping (PRD §14.2, conventions/12): when a
 * tile was last scouted by which adapter version, and how much it holds.
 */
final class TileCacheState extends Model
{
    protected $table = 'tile_cache_state';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_scouted_at' => 'immutable_datetime',
        ];
    }
}
