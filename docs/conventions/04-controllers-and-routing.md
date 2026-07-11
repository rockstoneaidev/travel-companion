# 04 — Controllers & routing

We have **two HTTP delivery surfaces over one domain**:

- **Inertia** (`routes/web.php`) — the Phase 1 web app. Returns `Inertia::render()` with props.
- **JSON API** (`routes/api.php`, `/api/v1`, Sanctum) — the versioned contract the Phase 2 mobile
  client will consume.

They are siblings. Neither is built on the other. **Both are thin wrappers over the same domain
action.** If the two surfaces contain different logic, one of them is wrong.

## Layout

```
app/Http/Controllers/
    Auth/                       (starter kit — leave it alone)
    Settings/                   (starter kit)
    Web/
        TripController.php              → Inertia
        OpportunityController.php
    Api/V1/
        TripController.php              → JSON
        OpportunityController.php
```

Namespaces mirror the path: `App\Http\Controllers\Api\V1\TripController`.

The `V1` in the namespace is load-bearing. When `/api/v2` arrives, `Api/V2/` is a new directory of
thin controllers over the *same, unchanged* domain actions. That is only possible if no logic lives
here.

## The shape of a controller method

```php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\CreateTrip;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Trips\StoreTripRequest;
use App\Http\Resources\Api\V1\TripResource;

final class TripController extends Controller
{
    public function store(StoreTripRequest $request, CreateTrip $createTrip): TripResource
    {
        $trip = $createTrip($request->toData());

        return new TripResource($trip);
    }
}
```

Three lines. Validate (in the Request), delegate (to the Action), serialize (with the Resource).

The Inertia twin:

```php
namespace App\Http\Controllers\Web;

final class TripController extends Controller
{
    public function store(StoreTripRequest $request, CreateTrip $createTrip): RedirectResponse
    {
        $createTrip($request->toData());          // same action, same request class

        return to_route('trips.index')->with('status', 'trip-created');
    }
}
```

## Hard rules

- **No business logic.** No `if` that a product person would recognise as a decision. No
  transactions. No `DB::` calls. No `Model::query()`. If your controller has one, it belongs in an
  Action or a Query.
- **No Eloquent query building in the controller.** `Trip::where(...)->with(...)->get()` is a Query
  object's job. The controller may accept a route-model-bound instance.
- **Inject the Action**, don't `new` it and don't resolve it from the container by hand.
- **Type the return.** `TripResource`, `JsonResponse`, `RedirectResponse`, `Response` (Inertia).
- **`final class`**, extends `Controller`. Single-action controllers use `__invoke`.
- Controllers are **resourceful**: `index show store update destroy`. A verb-named method
  (`acceptRecommendation`) is a sign you want a separate controller
  (`RecommendationAcceptanceController::store`). Some verbs are genuinely not CRUD — that's fine,
  but reach for the resource first.

## Routing

```php
// routes/api.php
Route::middleware('auth:sanctum')->prefix('v1')->name('api.v1.')->group(function () {
    Route::apiResource('trips', TripController::class);
    Route::post('recommendations/{recommendation}/accept', [RecommendationAcceptanceController::class, 'store'])
        ->name('recommendations.accept');
});
```

- Every route is **named**. Ziggy exports names to the frontend; an unnamed route is unreachable
  from React without hardcoding a URL.
- Route-model binding by default. Scope nested bindings so a user cannot read another user's child:
  `Route::apiResource('trips.segments', ...)->scoped()`.
- Group by middleware, not per-route repetition.
- Rate limit anything that hits a paid external API or an LLM (`throttle:` + a named limiter in
  `AppServiceProvider`). A recommendation endpoint that fans out to Google and an LLM is not a
  free endpoint.

## Authorization

Authorize in the **Form Request** (`authorize()`) or via a **route middleware**
(`->can('update', 'trip')`). Not with an `if` in the controller body.

Policies live at `app/Domain/{Module}/Policies/`, attached with `#[UsePolicy]` on the model
([01](01-domain-modules.md)).

Every trip-scoped resource must be authorized against trip ownership. There is no "it's just a read"
exemption — location data is the most sensitive thing this product holds (PRD §16).

## Responses

- **API:** always an API Resource ([06](06-resources-and-serialization.md)). Never
  `response()->json($model)`, which leaks every column including ones added later.
- **Inertia:** props are also built from Resources or DTOs. `Inertia::render('trips/index', [...])`.
  Never pass a raw model into props for the same reason.
- **Errors:** throw a domain exception. The handler in `bootstrap/app.php` maps it to a status code
  and an error envelope, once, for both surfaces. Do not `abort()` from a controller to express a
  domain condition; `abort(404)` on a genuinely missing route resource is fine.
- **Status codes:** `201` + the resource on store. `200` on update. `204` on destroy.
  Inertia writes redirect (`to_route`) — never return JSON from an Inertia endpoint.

## Checklist

- [ ] Method is < 10 lines and contains no decision.
- [ ] The Action it calls is the same one the other delivery surface calls.
- [ ] Request class does the validating and the authorizing.
- [ ] Resource does the serializing.
- [ ] Route is named, model-bound, and rate-limited if it costs money.
