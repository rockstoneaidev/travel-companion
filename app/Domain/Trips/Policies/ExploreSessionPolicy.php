<?php

declare(strict_types=1);

namespace App\Domain\Trips\Policies;

use App\Domain\Trips\Models\ExploreSession;
use App\Models\User;

final class ExploreSessionPolicy
{
    public function view(User $user, ExploreSession $session): bool
    {
        return $session->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Ending a session, and recording context events against it. */
    public function update(User $user, ExploreSession $session): bool
    {
        return $session->user_id === $user->id;
    }
}
