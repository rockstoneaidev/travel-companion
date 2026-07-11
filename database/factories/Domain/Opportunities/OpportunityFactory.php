<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Opportunities;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
final class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'kind' => OpportunityKind::Evergreen,
            'status' => OpportunityStatus::RawCandidate,
            'title' => null,
            'summary' => null,
            'friction' => [],
            'h3_index' => '88'.fake()->regexify('[0-9a-f]{13}'),
            'expires_at' => now()->addHours(6),
        ];
    }
}
