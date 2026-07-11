<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ExploreSessions;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the API and the Inertia controller — the input is the same because
 * both call the same action (conventions/05).
 */
final class StoreExploreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ExploreSession::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Coordinate ranges are validated explicitly: a swapped lat/lng that
            // passes validation puts the user in the ocean (conventions/05).
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],

            'time_budget_minutes' => [
                'required', 'integer',
                'min:'.config('trips.session.min_time_budget_minutes'),
                'max:'.config('trips.session.max_time_budget_minutes'),
            ],
            'travel_mode' => ['required', Rule::enum(TravelMode::class)],

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
            origin: new Coordinates(
                lat: (float) $this->input('origin.lat'),
                lng: (float) $this->input('origin.lng'),
            ),
            timeBudgetMinutes: (int) $this->integer('time_budget_minutes'),
            travelMode: $this->enum('travel_mode', TravelMode::class),
            heading: $this->input('heading') === null ? null : (int) $this->integer('heading'),
            destinationPoint: is_array($destination) ? new Coordinates(
                lat: (float) $destination['lat'],
                lng: (float) $destination['lng'],
            ) : null,
        );
    }
}
