<?php

declare(strict_types=1);

namespace App\Domain\Context\Models;

use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Places\Casts\AsCoordinates;
use Database\Factories\Domain\Context\ContextEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A session-scoped context observation (PRD §6.6, payload in §14.5).
 *
 * Holds `explore_session_id` / `trip_id` as plain keys and declares no relation
 * to the Trips module's models: cross-module traffic goes through contracts and
 * DTOs (conventions/01, enforced by tests/Arch/ConventionsTest.php).
 */
#[UseFactory(ContextEventFactory::class)]
final class ContextEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'location' => AsCoordinates::class,
            'movement_mode' => MovementMode::class,
            'app_state' => AppState::class,
            'accuracy_meters' => 'integer',
            'speed_mps' => 'float',
            'heading' => 'integer',
            'battery_level' => 'float',
            'is_low_power_mode' => 'boolean',
            'available_minutes' => 'integer',
            'companions' => 'array',
        ];
    }
}
