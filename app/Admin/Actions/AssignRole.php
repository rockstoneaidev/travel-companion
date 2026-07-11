<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssignRole
{
    /**
     * Additive grant, used by the CLI bootstrap (`user:assign-role`). A null
     * actor means the console — server access already implies superadmin.
     */
    public function __invoke(User $user, Role $role, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $role, $actor): void {
            $user->assignRole($role->value);

            activity()
                ->causedBy($actor)
                ->performedOn($user)
                ->withProperties(['role' => $role->value])
                ->log('user.role_assigned');
        });
    }
}
