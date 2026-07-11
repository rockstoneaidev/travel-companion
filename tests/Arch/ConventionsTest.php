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
