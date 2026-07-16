<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Places\Data\Coordinates;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a planned trip. The body is optional: a trip with an anchor needs nothing, but a
 * trip planned by name only ("Fjäderholmarna") can be started from where the traveller is
 * standing — so the client may send the current position, and both halves must come together.
 */
final class StartTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route gates this with ->can('update', 'trip')
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }

    /** The live origin the client sent, or null when it sent none. */
    public function toCoordinates(): ?Coordinates
    {
        if (! $this->filled('lat') || ! $this->filled('lng')) {
            return null;
        }

        return new Coordinates((float) $this->input('lat'), (float) $this->input('lng'));
    }
}
