<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\NewTripData;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The OPTIONAL planner path (PRD §6.6). Note what is absent: no `status`. A
 * client cannot POST a trip straight into `active` — that is the implicit
 * clustering's decision and it is guarded by a unique index.
 */
final class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Trip::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],

            'anchor_point' => ['sometimes', 'nullable', 'array'],
            'anchor_point.lat' => ['required_with:anchor_point', 'numeric', 'between:-90,90'],
            'anchor_point.lng' => ['required_with:anchor_point', 'numeric', 'between:-180,180'],

            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            // The trip can't end before it starts — a departure earlier than the planned
            // start is a typo, not a plan.
            'departs_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_start_at'],
        ];
    }

    public function toData(): NewTripData
    {
        $anchor = $this->input('anchor_point');

        return new NewTripData(
            userId: (int) $this->user()->id,
            name: $this->string('name')->toString(),
            anchorPoint: is_array($anchor) ? new Coordinates(
                lat: (float) $anchor['lat'],
                lng: (float) $anchor['lng'],
            ) : null,
            plannedStartAt: $this->date('planned_start_at') !== null ? CarbonImmutable::parse($this->input('planned_start_at')) : null,
            departsAt: $this->date('departs_at') !== null ? CarbonImmutable::parse($this->input('departs_at')) : null,
        );
    }
}
