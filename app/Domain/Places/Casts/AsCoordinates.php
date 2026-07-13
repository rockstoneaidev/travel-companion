<?php

declare(strict_types=1);

namespace App\Domain\Places\Casts;

use App\Domain\Places\Data\Coordinates;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Casts a PostGIS `geography(Point, 4326)` column to a Coordinates value object
 * and back. Writing goes out as an `ST_GeogFromText` expression — the same
 * pattern the E1 factories use.
 *
 * @implements CastsAttributes<Coordinates|null, Coordinates|null>
 */
final class AsCoordinates implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Coordinates
    {
        if ($value instanceof Coordinates) {
            return $value;
        }

        return is_string($value) ? Coordinates::fromEwkbHex($value) : null;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }

        /*
         * A raw PostGIS expression passes straight through.
         *
         * The write path below EMITS an Expression, so refusing to accept one was the cast
         * contradicting itself — and it mattered the moment `places_core.location` gained
         * this cast: the ingest path (ResolveSourceItem) and the place factory both build
         * the geography in SQL, as `ST_SetSRID(ST_MakePoint(…))`, and a cast that rejected
         * them would have made adding it to Place a choice between a readable model and a
         * working importer.
         */
        if ($value instanceof Expression) {
            return [$key => $value];
        }

        if (! $value instanceof Coordinates) {
            throw new InvalidArgumentException("Attribute [{$key}] must be a Coordinates instance or a raw PostGIS expression.");
        }

        return [$key => DB::raw(sprintf("ST_GeogFromText('SRID=4326;%s')", $value->toWkt()))];
    }
}
