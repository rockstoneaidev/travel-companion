<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests — docs/conventions/ enforced by the build
|--------------------------------------------------------------------------
|
| These rules exist so that the conventions are not a document someone has to
| remember during code review. Each test below cites the convention it enforces.
|
| Most of app/Domain/ is still empty. Arch expectations over an empty namespace
| pass vacuously, so these rules are dormant now and become load-bearing the
| moment the first class lands in a module. That is intentional — do not delete
| a rule because it currently has nothing to check.
|
*/

/** The twelve modules, fixed by PRD §14.1. */
const MODULES = [
    'Trips', 'Context', 'Profiles', 'Opportunities', 'Places', 'Recommendations',
    'Notifications', 'Sources', 'Agent', 'Feedback', 'Privacy', 'Curation',
];

/*
|--------------------------------------------------------------------------
| 01 — The domain does not know it is behind HTTP
|--------------------------------------------------------------------------
*/

arch('domain code is transport-agnostic')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\RedirectResponse',
        'Inertia\Inertia',
        'Illuminate\Support\Facades\Route',
    ]);

arch('domain code does not abort or build responses')
    ->expect('App\Domain')
    ->not->toUse(['abort', 'abort_if', 'abort_unless', 'response', 'request', 'redirect', 'to_route']);

arch('domain code does not depend on HTTP resources or requests')
    ->expect('App\Domain')
    ->not->toUse(['App\Http\Resources', 'App\Http\Requests', 'App\Http\Controllers']);

/*
|--------------------------------------------------------------------------
| 01 — Modules do not reach into each other's internals
|--------------------------------------------------------------------------
|
| A module's Models are internal. Cross-module traffic goes through Contracts
| and Data (DTOs). Another module may hold a place_id; never a Place.
|
*/

foreach (MODULES as $module) {
    $foreignInternals = [];

    foreach (MODULES as $other) {
        if ($other === $module) {
            continue;
        }

        $foreignInternals[] = "App\\Domain\\{$other}\\Models";
        $foreignInternals[] = "App\\Domain\\{$other}\\Actions";
        $foreignInternals[] = "App\\Domain\\{$other}\\Queries";
    }

    arch("{$module} does not reach into another module's internals")
        ->expect("App\\Domain\\{$module}")
        ->not->toUse($foreignInternals);
}

/*
|--------------------------------------------------------------------------
| 04 / 08 — Controllers and jobs are thin wrappers
|--------------------------------------------------------------------------
*/

arch('controllers do not touch the database directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Database\Query\Builder',
    ]);

arch('jobs do not touch the database directly')
    ->expect('App\Jobs')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Builder',
    ]);

arch('jobs are queued')
    ->expect('App\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

/*
|--------------------------------------------------------------------------
| 01 — There is no app/Services/. Services live in their owning module.
|--------------------------------------------------------------------------
*/

arch('there is no top-level Services namespace')
    ->expect('App\Services')
    ->toBeUsedInNothing();

/*
|--------------------------------------------------------------------------
| 02 — Enums
|--------------------------------------------------------------------------
*/

foreach (MODULES as $module) {
    arch("{$module} enums are string-backed")
        ->expect("App\\Domain\\{$module}\\Enums")
        ->toBeStringBackedEnums();
}

arch('cross-module enums are string-backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums()
    ->ignoring('App\Enums\Concerns');

/*
|--------------------------------------------------------------------------
| 01 — Shape of the building blocks
|--------------------------------------------------------------------------
*/

foreach (MODULES as $module) {
    arch("{$module} models extend Eloquent")
        ->expect("App\\Domain\\{$module}\\Models")
        ->toExtend('Illuminate\Database\Eloquent\Model');

    arch("{$module} contracts are interfaces")
        ->expect("App\\Domain\\{$module}\\Contracts")
        ->toBeInterfaces();

    arch("{$module} DTOs are readonly")
        ->expect("App\\Domain\\{$module}\\Data")
        ->toBeReadonly();
}

arch('domain classes declare strict types')
    ->expect('App\Domain')
    ->toUseStrictTypes();

/*
|--------------------------------------------------------------------------
| docs/ADMIN.md §4 — the App\Admin platform namespace
|--------------------------------------------------------------------------
|
| Admin platform code (user/role management, audit reading, dashboard
| composition) follows the same layering discipline as a domain module: it is
| transport-agnostic, and the dependency on the domain is one-way — App\Admin
| may consume domain contracts, App\Domain never references App\Admin.
|
*/

arch('admin platform code is transport-agnostic')
    ->expect('App\Admin')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\RedirectResponse',
        'Inertia\Inertia',
        'Illuminate\Support\Facades\Route',
        'App\Http\Resources',
        'App\Http\Requests',
        'App\Http\Controllers',
    ]);

arch('admin platform code does not abort or build responses')
    ->expect('App\Admin')
    ->not->toUse(['abort', 'abort_if', 'abort_unless', 'response', 'request', 'redirect', 'to_route']);

arch('the domain never depends on the admin platform')
    ->expect('App\Domain')
    ->not->toUse('App\Admin');

arch('the admin platform does not reach into module internals')
    ->expect('App\Admin')
    ->not->toUse(array_merge(...array_map(
        fn (string $module): array => [
            "App\\Domain\\{$module}\\Models",
            "App\\Domain\\{$module}\\Actions",
            "App\\Domain\\{$module}\\Queries",
        ],
        MODULES,
    )));

arch('admin DTOs are readonly')
    ->expect('App\Admin\Data')
    ->toBeReadonly();

arch('admin contracts are interfaces')
    ->expect('App\Admin\Contracts')
    ->toBeInterfaces();

arch('admin platform code declares strict types')
    ->expect('App\Admin')
    ->toUseStrictTypes();

/*
|--------------------------------------------------------------------------
| docs/COST.md — the App\Cost platform namespace
|--------------------------------------------------------------------------
|
| Metering is cross-cutting: it is spent in Agent, in Context, in Sources and in
| Ingest, so it cannot live inside any one module (conventions/01), and the old
| CostMeter sitting in Domain\Recommendations was already a quiet violation of that
| — the Gemini client, in another module entirely, reached into it.
|
| The difference from App\Admin, and the reason the rules below are not a copy of
| its rules: the DOMAIN MAY USE App\Cost. It has to — the thing that spends the money
| is the thing that must record it, and a meter another layer has to remember to call
| on the domain's behalf is a meter that will be forgotten. So the dependency runs the
| other way from Admin's, deliberately, and the discipline that keeps it honest is
| that App\Cost may not reach BACK into module internals.
|
*/

arch('cost platform code is transport-agnostic')
    ->expect('App\Cost')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Inertia\Inertia',
        'Illuminate\Support\Facades\Route',
        'App\Http\Resources',
        'App\Http\Requests',
        'App\Http\Controllers',
    ]);

arch('the cost platform does not reach into module internals')
    ->expect('App\Cost')
    ->not->toUse(array_merge(...array_map(
        fn (string $module): array => [
            "App\\Domain\\{$module}\\Models",
            "App\\Domain\\{$module}\\Actions",
            "App\\Domain\\{$module}\\Queries",
        ],
        MODULES,
    )));

arch('cost DTOs are readonly')
    ->expect('App\Cost\Data')
    ->toBeReadonly();

arch('cost platform code declares strict types')
    ->expect('App\Cost')
    ->toUseStrictTypes();

/*
|--------------------------------------------------------------------------
| E22 — the App\Auth platform namespace
|--------------------------------------------------------------------------
|
| Identity is a platform concern, not a thirteenth domain module (01 fixes the
| twelve). It gets the same treatment as App\Admin: transport-agnostic, so the
| Phase 2 mobile client can hand it a SocialIdentity built from a Google ID
| token and reuse every linking and allowlist decision unchanged.
|
| Socialite in particular must not leak in — it is the *web redirect* adapter,
| and a domain that imports it cannot be driven by a native client.
|
*/

arch('auth platform code is transport-agnostic')
    ->expect('App\Auth')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\RedirectResponse',
        'Inertia\Inertia',
        'Illuminate\Support\Facades\Route',
        'Laravel\Socialite\Facades\Socialite',
        'Laravel\Socialite\Contracts\User',
        'App\Http\Resources',
        'App\Http\Requests',
        'App\Http\Controllers',
    ]);

arch('auth platform code does not abort or build responses')
    ->expect('App\Auth')
    ->not->toUse(['abort', 'abort_if', 'abort_unless', 'response', 'request', 'redirect', 'to_route']);

arch('the domain never depends on the auth platform')
    ->expect('App\Domain')
    ->not->toUse('App\Auth');

arch('auth DTOs are readonly')
    ->expect('App\Auth\Data')
    ->toBeReadonly();

arch('auth enums are string-backed')
    ->expect('App\Auth\Enums')
    ->toBeStringBackedEnums();

arch('auth platform code declares strict types')
    ->expect('App\Auth')
    ->toUseStrictTypes();

/*
|--------------------------------------------------------------------------
| Hygiene
|--------------------------------------------------------------------------
*/

arch('no debugging statements survive')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('no environment reads outside config')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('App\Providers');
