<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Sources;

use App\Domain\Sources\Models\SourceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceItem>
 */
final class SourceItemFactory extends Factory
{
    protected $model = SourceItem::class;

    public function definition(): array
    {
        return [
            'source' => 'osm',
            'external_id' => 'node/'.fake()->unique()->numberBetween(1, 10_000_000),
            'license' => SourceLicense::Odbl,
            'storage_policy' => StoragePolicy::Persistable,
            'credibility_tier' => CredibilityTier::Open,
            'payload' => ['name' => fake()->streetName(), 'tags' => ['tourism' => 'viewpoint']],
            'h3_index' => '88'.fake()->regexify('[0-9a-f]{13}'),
            'source_adapter_version' => 'v1',
            'attribution' => '© OpenStreetMap contributors, ODbL',
            'retrieved_at' => now(),
        ];
    }

    public function edgeOnly(): self
    {
        return $this->state([
            'source' => 'google_places',
            'license' => SourceLicense::Proprietary,
            'storage_policy' => StoragePolicy::EdgeOnly,
            'credibility_tier' => CredibilityTier::Reference,
        ]);
    }
}
