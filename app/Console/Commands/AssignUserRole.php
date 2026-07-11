<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Admin\Actions\AssignRole;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps the first superadmin (docs/ADMIN.md §3.1):
 *
 *   php artisan user:assign-role mats@example.com superadmin
 */
final class AssignUserRole extends Command
{
    protected $signature = 'user:assign-role {email : The user\'s email address} {role : One of: admin, superadmin}';

    protected $description = 'Grant a role to a user';

    public function handle(AssignRole $assignRole): int
    {
        $role = Role::tryFrom($this->argument('role'));

        if ($role === null) {
            $this->error(sprintf('Unknown role "%s". Valid roles: %s.', $this->argument('role'), implode(', ', Role::values())));

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error(sprintf('No user with email "%s".', $this->argument('email')));

            return self::FAILURE;
        }

        $assignRole($user, $role);

        $this->info(sprintf('%s is now %s.', $user->email, $role->value));

        return self::SUCCESS;
    }
}
