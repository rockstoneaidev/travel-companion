<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use App\Admin\Exceptions\OperatorCannotModifyOwnRoles;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncUserRoles
{
    /**
     * @param  list<Role>  $roles
     */
    public function __invoke(User $actor, User $target, array $roles): void
    {
        if ($actor->is($target)) {
            throw new OperatorCannotModifyOwnRoles;
        }

        DB::transaction(function () use ($actor, $target, $roles): void {
            $before = $target->getRoleNames()->all();

            $target->syncRoles(array_map(fn (Role $role): string => $role->value, $roles));

            activity()
                ->causedBy($actor)
                ->performedOn($target)
                ->withProperties([
                    'old' => $before,
                    'new' => $target->getRoleNames()->all(),
                ])
                ->log('user.roles_synced');
        });
    }
}
