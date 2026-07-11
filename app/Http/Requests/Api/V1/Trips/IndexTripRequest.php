<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Trips\Data\ListTripsCriteria;
use App\Domain\Trips\Enums\TripSortField;
use App\Domain\Trips\Enums\TripStatus;
use App\Enums\SortDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** conventions/07: `sort_by` is an enum, `per_page` is capped. */
final class IndexTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;   // the query is scoped to the caller
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', Rule::enum(TripSortField::class)],
            'sort_dir' => ['sometimes', Rule::enum(SortDirection::class)],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(TripStatus::class)],
        ];
    }

    public function toCriteria(): ListTripsCriteria
    {
        return new ListTripsCriteria(
            userId: (int) $this->user()->id,
            statuses: array_map(
                fn (string $status): TripStatus => TripStatus::from($status),
                array_values((array) $this->input('status', [])),
            ),
            sortBy: $this->enum('sort_by', TripSortField::class) ?? TripSortField::LastSessionAt,
            sortDir: $this->enum('sort_dir', SortDirection::class) ?? SortDirection::Desc,
            perPage: (int) $this->integer('per_page', 25),
        );
    }
}
