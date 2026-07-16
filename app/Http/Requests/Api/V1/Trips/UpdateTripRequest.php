<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Trips\Data\TripUpdateData;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `PATCH /api/v1/trips/{trip}` — rename, mark ended (PRD §14.5). Nothing else. */
final class UpdateTripRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],

            // Restricted to the only transition a client may ask for: → completed.
            'status' => ['sometimes', Rule::enum(TripStatus::class)->only([TripStatus::Completed])],

            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'departs_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_start_at'],
        ];
    }

    public function toData(): TripUpdateData
    {
        // Dates arrive as a pair when the dates form is saved. `datesProvided` distinguishes
        // "the user edited dates (maybe clearing one)" from "this request is only a rename".
        $datesProvided = $this->has('planned_start_at') || $this->has('departs_at');

        return new TripUpdateData(
            name: $this->has('name') ? $this->string('name')->toString() : null,
            complete: $this->enum('status', TripStatus::class) === TripStatus::Completed,
            plannedStartAt: $this->input('planned_start_at') !== null ? CarbonImmutable::parse($this->input('planned_start_at')) : null,
            departsAt: $this->input('departs_at') !== null ? CarbonImmutable::parse($this->input('departs_at')) : null,
            datesProvided: $datesProvided,
        );
    }
}
