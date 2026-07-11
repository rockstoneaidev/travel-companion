<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Trips\Data\TripUpdateData;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
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
        ];
    }

    public function toData(): TripUpdateData
    {
        return new TripUpdateData(
            name: $this->has('name') ? $this->string('name')->toString() : null,
            complete: $this->enum('status', TripStatus::class) === TripStatus::Completed,
        );
    }
}
