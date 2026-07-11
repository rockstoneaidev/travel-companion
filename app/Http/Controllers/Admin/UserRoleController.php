<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SyncUserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\UpdateUserRolesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class UserRoleController extends Controller
{
    public function update(UpdateUserRolesRequest $request, User $user, SyncUserRoles $syncUserRoles): RedirectResponse
    {
        $syncUserRoles($request->user(), $user, $request->roles());

        return back()->with('status', 'roles-updated');
    }
}
