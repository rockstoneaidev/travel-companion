<?php

namespace App\Providers;

use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Places\Services\PostgresTileIndexer;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            TileIndexer::class,
            PostgresTileIndexer::class,
        );
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

        $this->configureRateLimiters();
    }

    /**
     * Named limiters for the endpoints that cost money (conventions/04). The
     * session feed will fan out to paid APIs and an LLM (PRD §14.3); context
     * events are cheap but high-frequency and must not be able to flood the
     * table.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('explore-feed', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('context-events', fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
