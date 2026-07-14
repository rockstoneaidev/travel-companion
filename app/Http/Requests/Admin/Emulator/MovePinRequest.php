<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Emulator;

use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Enums\AppState;
use App\Domain\Context\Enums\MovementMode;
use App\Domain\Places\Data\Coordinates;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One tick of the pin (ADMIN §6) — which is to say, one perfectly ordinary context
 * event. There is no emulator-shaped ingestion path; that is the point.
 */
final class MovePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::EmulateLocation->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],

            'location' => ['required', 'array'],
            'location.lat' => ['required', 'numeric', 'between:-90,90'],
            'location.lng' => ['required', 'numeric', 'between:-180,180'],

            'movement' => ['sometimes', 'nullable', 'array'],
            'movement.mode' => ['sometimes', 'nullable', Rule::enum(MovementMode::class)],
            'movement.speed_mps' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:200'],
            'movement.heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],
        ];
    }

    public function toData(): NewContextEventData
    {
        return new NewContextEventData(
            exploreSessionId: (string) $this->input('session_id'),
            occurredAt: null,
            location: new Coordinates(
                (float) $this->input('location.lat'),
                (float) $this->input('location.lng'),
            ),
            /*
             * A pin knows exactly where it is. Reporting 5 m rather than leaving it null
             * matters because `SessionAnchor` raises the drift threshold to the device's
             * own claimed accuracy (E46) — a null would leave the 400 m default standing,
             * which is right for a phone and wrong for a simulation.
             */
            accuracyMeters: 5,
            movementMode: $this->input('movement.mode') === null
                ? null
                : MovementMode::from((string) $this->input('movement.mode')),
            speedMps: $this->input('movement.speed_mps') === null ? null : (float) $this->input('movement.speed_mps'),
            heading: $this->input('movement.heading') === null ? null : (int) $this->input('movement.heading'),
            appState: AppState::Foreground,
        );
    }
}
