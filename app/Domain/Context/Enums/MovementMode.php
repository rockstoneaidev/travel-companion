<?php

declare(strict_types=1);

namespace App\Domain\Context\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The client's classification of how the user is moving, from the context
 * event payload (PRD §14.5 — `movement.mode`).
 *
 * This is an *observation*, not the session's declared `TravelMode`: a user who
 * declared a `drive` session is `still` while parked. They are deliberately
 * separate types.
 */
enum MovementMode: string
{
    use HasOptions;

    case Still = 'still';
    case Walking = 'walking';
    case Running = 'running';
    case Cycling = 'cycling';
    case Driving = 'driving';
    case Transit = 'transit';
    case Unknown = 'unknown';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
