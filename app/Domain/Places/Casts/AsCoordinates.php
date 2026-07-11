<?php

declare(strict_types=1);

namespace App\Domain\Places\Casts;

use App\Domain\Places\Data\Coordinates;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
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

        if (! $value instanceof Coordinates) {
            throw new InvalidArgumentException("Attribute [{$key}] must be a Coordinates instance.");
        }

        return [$key => DB::raw(sprintf("ST_GeogFromText('SRID=4326;%s')", $value->toWkt()))];
    }
}
