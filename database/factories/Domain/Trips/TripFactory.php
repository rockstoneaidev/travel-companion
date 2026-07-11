<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Trips;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
final class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        // Liljeholmen, Stockholm — the test region (PRD §8.0). Never (0, 0):
        // null island passes tests and hides bugs (conventions/11).
        $lat = 59.31 + fake()->randomFloat(4, -0.03, 0.03);
        $lng = 18.02 + fake()->randomFloat(4, -0.05, 0.05);

        return [
            'user_id' => User::factory(),
            'name' => null,
            'status' => TripStatus::Active,
            'source' => TripSource::Auto,
            'anchor_point' => new Coordinates($lat, $lng),
            'clustering_version' => config('trips.clustering.version'),
            'started_at' => now(),
            'last_session_at' => now(),
        ];
    }

    public function planned(): self
    {
        return $this->state(fn (): array => [
            'status' => TripStatus::Planned,
            'source' => TripSource::User,
            'name' => fake()->city(),
            'started_at' => null,
            'last_session_at' => null,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => TripStatus::Completed,
            'ended_at' => now(),
        ]);
    }

    public function at(float $lat, float $lng): self
    {
        return $this->state(fn (): array => ['anchor_point' => new Coordinates($lat, $lng)]);
    }
}
