<?php

declare(strict_types=1);

namespace Database\Factories\Auth;

use App\Auth\Enums\SocialProvider;
use App\Auth\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Google->value,
            'provider_user_id' => (string) fake()->unique()->numerify('##################'),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => fake()->imageUrl(),
            'last_login_at' => now(),
        ];
    }
}
