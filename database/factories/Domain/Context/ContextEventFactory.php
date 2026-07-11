<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Context;

use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContextEvent>
 */
final class ContextEventFactory extends Factory
{
    protected $model = ContextEvent::class;

    public function definition(): array
    {
        $lat = 59.31 + fake()->randomFloat(4, -0.03, 0.03);
        $lng = 18.02 + fake()->randomFloat(4, -0.05, 0.05);

        return [
            'explore_session_id' => ExploreSession::factory(),
            'trip_id' => fn (array $attributes): string => ExploreSession::query()
                ->findOrFail($attributes['explore_session_id'])->trip_id,
            'user_id' => fn (array $attributes): int => ExploreSession::query()
                ->findOrFail($attributes['explore_session_id'])->user_id,
            'occurred_at' => now(),
            'location' => new Coordinates($lat, $lng),
            'accuracy_meters' => fake()->numberBetween(5, 60),
            'movement_mode' => MovementMode::Walking,
            'speed_mps' => 1.2,
            'heading' => fake()->numberBetween(0, 359),
            'app_state' => AppState::Foreground,       // Phase 1 is foreground-only (PRD §8)
            'battery_level' => 0.64,
            'is_low_power_mode' => false,
            'available_minutes' => 90,
            'companions' => [],
        ];
    }
}
