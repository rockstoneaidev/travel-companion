<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Context\Data\NewTripContextData;
use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Context\Enums\PowerTier;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The background stream's payload (PRD §13.4, §14.5; E29).
 *
 * `location` and `power_tier` are REQUIRED — unlike the session payload, where everything
 * degrades gracefully. A background wake-up with no position is a wake-up with nothing to
 * say, and a phone that will not tell us which battery tier it used is a phone we cannot
 * hold to the battery contract.
 */
final class StoreTripContextEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $trip = $this->route('trip');

        return $trip instanceof Trip && ($this->user()?->can('update', $trip) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'timestamp' => ['sometimes', 'nullable', 'date'],

            'location' => ['required', 'array'],
            'location.lat' => ['required', 'numeric', 'between:-90,90'],
            'location.lng' => ['required', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20000'],

            'power_tier' => ['required', Rule::enum(PowerTier::class)],

            'movement' => ['sometimes', 'nullable', 'array'],
            'movement.mode' => ['sometimes', 'nullable', Rule::enum(MovementMode::class)],
            'movement.speed_mps' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:200'],
            'movement.heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],

            'app_state' => ['sometimes', 'nullable', Rule::enum(AppState::class)],

            'battery' => ['sometimes', 'nullable', 'array'],
            'battery.level' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'battery.low_power_mode' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function toData(): NewTripContextData
    {
        $timestamp = $this->input('timestamp');

        return new NewTripContextData(
            location: new Coordinates(
                (float) $this->input('location.lat'),
                (float) $this->input('location.lng'),
            ),
            powerTier: PowerTier::from((string) $this->input('power_tier')),
            occurredAt: $timestamp === null ? null : CarbonImmutable::parse($timestamp),
            accuracyMeters: $this->input('location.accuracy_m') === null ? null : (int) $this->input('location.accuracy_m'),
            movementMode: $this->input('movement.mode') === null ? null : MovementMode::from((string) $this->input('movement.mode')),
            speedMps: $this->input('movement.speed_mps') === null ? null : (float) $this->input('movement.speed_mps'),
            heading: $this->input('movement.heading') === null ? null : (int) $this->input('movement.heading'),
            appState: $this->input('app_state') === null ? AppState::Background : AppState::from((string) $this->input('app_state')),
            batteryLevel: $this->input('battery.level') === null ? null : (float) $this->input('battery.level'),
            isLowPowerMode: $this->input('battery.low_power_mode') === null ? null : (bool) $this->input('battery.low_power_mode'),
        );
    }
}
