<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Places;

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Place>
 */
final class PlaceFactory extends Factory
{
    protected $model = Place::class;

    public function definition(): array
    {
        // Jitter around Liljeholmen, Stockholm — the test region (PRD §8.0).
        $lng = 18.02 + fake()->randomFloat(4, -0.05, 0.05);
        $lat = 59.31 + fake()->randomFloat(4, -0.03, 0.03);

        $type = fake()->randomElement([
            PlaceType::Cafe,
            PlaceType::Church,
            PlaceType::Viewpoint,
            PlaceType::Castle,
            PlaceType::Bakery,
            PlaceType::Gallery,
        ]);

        return [
            'name' => fake()->streetName(),
            'alt_names' => [],
            'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng, $lat)),
            'h3_index' => '88'.fake()->regexify('[0-9a-f]{13}'),
            'type' => $type,
            'type_domain' => $type->domain(),
            'facets' => $type->baseFacets(),
            'source_tags' => [],
            'taxonomy_version' => 1,
            'source' => 'osm',
            'attribute_sources' => [],
        ];
    }
}
