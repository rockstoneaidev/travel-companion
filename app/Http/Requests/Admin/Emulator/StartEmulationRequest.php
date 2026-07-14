<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Emulator;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartEmulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::EmulateLocation->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],

            'travel_mode' => ['required', Rule::enum(TravelMode::class)],
            'time_budget_minutes' => [
                'required', 'integer',
                'min:'.config('trips.session.min_time_budget_minutes'),
                'max:'.config('trips.session.max_time_budget_minutes'),
            ],

            'heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],

            'destination_point' => ['sometimes', 'nullable', 'array'],
            'destination_point.lat' => ['required_with:destination_point', 'numeric', 'between:-90,90'],
            'destination_point.lng' => ['required_with:destination_point', 'numeric', 'between:-180,180'],
        ];
    }

    public function toData(): NewExploreSessionData
    {
        $destination = $this->input('destination_point');

        return new NewExploreSessionData(
            userId: (int) $this->user()->id,
            origin: new Coordinates((float) $this->input('origin.lat'), (float) $this->input('origin.lng')),
            timeBudgetMinutes: (int) $this->integer('time_budget_minutes'),
            travelMode: TravelMode::from((string) $this->input('travel_mode')),
            heading: $this->input('heading') === null ? null : (int) $this->input('heading'),
            destinationPoint: is_array($destination)
                ? new Coordinates((float) $destination['lat'], (float) $destination['lng'])
                : null,
            /*
             * The one line this whole class exists for.
             *
             * `context_source` is NOT a field in `rules()`, deliberately: it cannot be
             * sent, only granted. The session is emulated because it was started from
             * behind the `location_emulate` permission, not because a payload said so
             * (ADMIN §6).
             */
            contextSource: ContextSource::Emulated,
        );
    }
}
