<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A proposed home zone (E40). Holds a res-8 cell and never a coordinate — see the
 * migration for why that is the entire point.
 */
final class InferredHomeZone extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
