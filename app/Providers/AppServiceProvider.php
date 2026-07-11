<?php

namespace App\Providers;

use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Places\Services\PostgresTileIndexer;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Places\Services\Scouts\CuratedScout;
use App\Domain\Places\Services\Scouts\HistoryScout;
use App\Domain\Places\Services\Scouts\NatureScout;
use App\Domain\Places\Services\Scouts\NearbyPlaceScout;
use App\Domain\Places\Services\Scouts\UnusualnessScout;
use App\Domain\Places\Services\TileCache;
use App\Domain\Sources\Services\ProvideResolvableItems;
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
        $this->app->singleton(
            ResolvableItems::class,
            ProvideResolvableItems::class,
        );

        // The M1 scouts (E5). Order is presentation-neutral; the cache key is
        // per scout, so adding one never invalidates another.
        $this->app->tag([
            NearbyPlaceScout::class,
            HistoryScout::class,
            NatureScout::class,
            UnusualnessScout::class,
            CuratedScout::class,
        ], 'tile-scouts');

        $this->app->singleton(ScoutRunner::class, function ($app) {
            return new ScoutRunner(
                $app->make(TileCache::class),
                $app->tagged('tile-scouts'),
            );
        });
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
