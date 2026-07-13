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
    case ViewCosts = 'costs_view';

    /**
     * Held by NO role — superadmin-only, via Gate::before (ADMIN §3.2), like
     * `privacy_operate`.
     *
     * Seeing what we spend is an operator's job. Making the whole product quieter for
     * every user in the field is not: a pause degrades the voice to the template and
     * routing to the estimator for everybody, and that is a decision the person who
     * owns the bill should make.
     */
    case PauseCost = 'cost_pause';

    public function label(): string
    {
        return match ($this) {
            self::AccessAdmin => 'Access the admin console',
            self::ViewOps => 'View Horizon & Pulse',
            self::ViewUsers => 'View users',
            self::ManageUserRoles => 'Manage user roles',
            self::ViewActivity => 'View the activity log',
            self::ViewCosts => 'View spend & the cost ledger',
            self::PauseCost => 'Pause & resume all paid calls',
        };
    }
}
