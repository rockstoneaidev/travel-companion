<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use App\Domain\Places\Casts\AsCoordinates;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Enums\AppealFacet;
use Database\Factories\Domain\Places\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The canonical place — GEO-CORE ZONE (conventions/03): this table is ODbL
 * and publishable. Only open data lands here; proprietary value attaches in
 * separate tables keyed by place_id. Persistence and relationships only —
 * no business rules (conventions/01).
 */
#[UseFactory(PlaceFactory::class)]
final class Place extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'places_core';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'alt_names' => 'array',
            'location' => AsCoordinates::class,
            'type' => PlaceType::class,
            'type_domain' => PlaceTypeDomain::class,
            'facets' => AsEnumCollection::of(AppealFacet::class),
            'source_tags' => 'array',
            'attribute_sources' => 'array',
            'taxonomy_version' => 'integer',
        ];
    }

    public function sourceIds(): HasMany
    {
        return $this->hasMany(PlaceSourceId::class);
    }
}
