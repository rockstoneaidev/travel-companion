<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One scout warm run's trace (E5 observability). Append-only. */
final class ScoutRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function hitRate(): float
    {
        return $this->tiles_requested === 0 ? 0.0 : round($this->tiles_hit / $this->tiles_requested, 4);
    }
}
