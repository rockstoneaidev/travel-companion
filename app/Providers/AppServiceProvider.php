<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Superadmin passes every gate. This is the only place a role name is
        // checked; everything else gates on permissions (docs/ADMIN.md §3).
        Gate::before(function ($user, string $ability): ?bool {
            return $user instanceof User && $user->hasRole(Role::Superadmin->value) ? true : null;
        });

        // Pulse dashboard access (docs/ADMIN.md §8). Horizon's twin gate lives
        // in HorizonServiceProvider.
        Gate::define('viewPulse', fn (User $user): bool => $user->can(Permission::ViewOps->value));
    }
}
