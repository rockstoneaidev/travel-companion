<?php

declare(strict_types=1);

namespace App\Domain\Sources\Models;

use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A region the product learned because someone actually went there (E48). */
final class DerivedRegion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'south' => 'float',
            'west' => 'float',
            'north' => 'float',
            'east' => 'float',
            'requested_at' => 'immutable_datetime',
        ];
    }

    /** A derived region IS an IngestRegion — it just was not written in a PHP file. */
    public function toIngestRegion(): IngestRegion
    {
        return new IngestRegion(
            key: $this->key,
            name: $this->name,
            south: $this->south,
            west: $this->west,
            north: $this->north,
            east: $this->east,
            locale: $this->locale,
        );
    }
}
