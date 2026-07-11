<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Operator roles (docs/ADMIN.md §3.1). Regular users hold no role.
 * Roles are bundles of permissions; code gates on Permission, never on a role
 * name — the only hasRole() check lives in the superadmin Gate::before.
 */
enum Role: string
{
    use HasOptions;

    case Admin = 'admin';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Superadmin => 'Superadmin',
        };
    }
}
