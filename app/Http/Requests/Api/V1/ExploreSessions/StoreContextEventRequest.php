<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ExploreSessions;

use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The context-event payload (PRD §14.5). Every field but the timestamp degrades
 * gracefully when absent — that is the spec, so `sometimes|nullable` is correct
 * here rather than lax.
 */
final class StoreContextEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('exploreSession');

        return $session instanceof ExploreSession
            && ($this->user()?->can('update', $session) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'timestamp' => ['sometimes', 'nullable', 'date'],

            'location' => ['sometimes', 'nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20000'],

            'movement' => ['sometimes', 'nullable', 'array'],
            'movement.mode' => ['sometimes', 'nullable', Rule::enum(MovementMode::class)],
            'movement.speed_mps' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:200'],
            'movement.heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],

            'app_state' => ['sometimes', 'nullable', Rule::enum(AppState::class)],

            'battery' => ['sometimes', 'nullable', 'array'],
            'battery.level' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'battery.low_power_mode' => ['sometimes', 'nullable', 'boolean'],

            'user_context' => ['sometimes', 'nullable', 'array'],
            'user_context.available_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'user_context.companions' => ['sometimes', 'nullable', 'array', 'max:10'],
            'user_context.companions.*' => ['string', 'max:40'],
        ];
    }

    public function toData(): NewContextEventData
    {
        /** @var ExploreSession $session */
        $session = $this->route('exploreSession');
        $location = $this->input('location');
        $timestamp = $this->input('timestamp');

        return new NewContextEventData(
            exploreSessionId: $session->id,
            occurredAt: $timestamp === null ? null : CarbonImmutable::parse($timestamp),
            location: is_array($location) ? new Coordinates(
                lat: (float) $location['lat'],
                lng: (float) $location['lng'],
            ) : null,
            accuracyMeters: $this->input('location.accuracy_m') === null ? null : (int) $this->input('location.accuracy_m'),
            movementMode: $this->input('movement.mode') === null ? null : MovementMode::from((string) $this->input('movement.mode')),
            speedMps: $this->input('movement.speed_mps') === null ? null : (float) $this->input('movement.speed_mps'),
            heading: $this->input('movement.heading') === null ? null : (int) $this->input('movement.heading'),
            appState: $this->input('app_state') === null ? null : AppState::from((string) $this->input('app_state')),
            batteryLevel: $this->input('battery.level') === null ? null : (float) $this->input('battery.level'),
            isLowPowerMode: $this->input('battery.low_power_mode') === null ? null : (bool) $this->input('battery.low_power_mode'),
            availableMinutes: $this->input('user_context.available_minutes') === null ? null : (int) $this->input('user_context.available_minutes'),
            companions: array_values(array_map('strval', (array) $this->input('user_context.companions', []))),
        );
    }
}
