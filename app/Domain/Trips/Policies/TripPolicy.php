<?php

declare(strict_types=1);

namespace App\Domain\Trips\Policies;

use App\Domain\Trips\Models\Trip;
use App\Models\User;

/**
 * Location data is the most sensitive thing this product holds (PRD §16): there
 * is no "it's just a read" exemption (conventions/04).
 */
final class TripPolicy
{
    public function view(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;   // any authenticated user may pre-create a planner trip
    }

    public function update(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    public function delete(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /** DELETE /trips/{trip}/location-history — the user's own data, always erasable by them. */
    public function eraseLocationHistory(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }
}
