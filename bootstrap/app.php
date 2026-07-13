<?php

use App\Admin\Exceptions\OperatorCannotModifyOwnRoles;
use App\Domain\Context\Exceptions\ExploreSessionNotAcceptingEvents;
use App\Domain\Trips\Exceptions\ExploreSessionAlreadyEnded;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MeterCost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // TLS is terminated at Traefik; trust its forwarded headers so Laravel
        // generates https URLs and honors the real client IP (SERVER-DEPLOYMENT.md).
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            MeterCost::class,
        ]);

        // The JSON API spends the same money the web app does (conventions/04: both
        // are thin wrappers over the same services), so it is metered the same way.
        // The Phase-2 mobile client must not arrive as a hole in the books.
        $middleware->api(append: [
            MeterCost::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Domain/platform exceptions map to HTTP once, here (conventions/01).
        // The domain never knows it is behind HTTP; both delivery surfaces get
        // their answer from this one place.
        $exceptions->render(function (OperatorCannotModifyOwnRoles $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        });

        // A session that is already over: the request is well-formed but the
        // state forbids it → 409.
        $exceptions->render(function (ExploreSessionAlreadyEnded $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT)
                : back()->withErrors(['explore_session' => $e->getMessage()]);
        });

        $exceptions->render(function (ExploreSessionNotAcceptingEvents $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT)
                : back()->withErrors(['explore_session' => $e->getMessage()]);
        });
    })->create();
