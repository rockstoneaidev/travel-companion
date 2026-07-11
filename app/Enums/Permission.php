<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The v1 permission set (docs/ADMIN.md §3.2). Seeded into the spatie tables by
 * RolesAndPermissionsSeeder; superadmin holds none directly and passes every
 * gate via Gate::before. Future console sections add their permission here.
 */
enum Permission: string
{
    use HasOptions;

    case AccessAdmin = 'admin_access';
    case ViewOps = 'ops_view';
    case ViewUsers = 'users_view';
    case ManageUserRoles = 'users_manage_roles';
    case ViewActivity = 'activity_view';

    public function label(): string
    {
        return match ($this) {
            self::AccessAdmin => 'Access the admin console',
            self::ViewOps => 'View Horizon & Pulse',
            self::ViewUsers => 'View users',
            self::ManageUserRoles => 'Manage user roles',
            self::ViewActivity => 'View the activity log',
        };
    }
}
