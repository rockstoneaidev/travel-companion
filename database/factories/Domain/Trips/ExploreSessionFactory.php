<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Trips;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExploreSession>
 */
final class ExploreSessionFactory extends Factory
{
    protected $model = ExploreSession::class;

    public function definition(): array
    {
        $lat = 59.31 + fake()->randomFloat(4, -0.03, 0.03);
        $lng = 18.02 + fake()->randomFloat(4, -0.05, 0.05);

        $trip = Trip::factory();
        $budget = fake()->randomElement([60, 120, 180, 240]);

        return [
            'trip_id' => $trip,
            'user_id' => fn (array $attributes): int => Trip::query()->findOrFail($attributes['trip_id'])->user_id,
            'origin' => new Coordinates($lat, $lng),
            'time_budget_minutes' => $budget,
            'travel_mode' => TravelMode::Walk,
            'heading' => null,
            'destination_point' => null,
            'status' => ExploreSessionStatus::Active,
            'started_at' => now(),
            'expires_at' => now()->addMinutes($budget),
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (): array => [
            'status' => ExploreSessionStatus::Ended,
            'ended_at' => now(),
        ]);
    }

    public function at(float $lat, float $lng): self
    {
        return $this->state(fn (): array => ['origin' => new Coordinates($lat, $lng)]);
    }
}
