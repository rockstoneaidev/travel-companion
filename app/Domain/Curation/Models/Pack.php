<?php

declare(strict_types=1);

namespace App\Domain\Curation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Regional Knowledge Pack (CURATION §2, DATA-SOURCES §8). */
final class Pack extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'h3_set' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CuratedItem::class);
    }
}
