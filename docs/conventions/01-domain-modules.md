# 01 — Domain modules & layering

The single most load-bearing convention in this repo. Everything else assumes it.

## The rule

**All product logic lives in `app/Domain/{Module}/`.** HTTP controllers, queue jobs and console
commands are *delivery mechanisms*: they translate an outside request into a call on a domain
service, and translate the result back out. They contain no business rules.

This exists because of the API-first boundary (CLAUDE.md): Inertia is Phase 1's web delivery layer,
the versioned JSON API is a second delivery layer, and the Phase 2 mobile client must be *additive*.
If logic leaks into an Inertia controller, the mobile client forces a backend rewrite. That is the
failure mode this convention prevents.

## The modules

The twelve modules are fixed by PRD §14.1. Do not invent a thirteenth without discussion.

```
Trips  Context  Profiles  Opportunities  Places  Recommendations
Notifications  Sources  Agent  Feedback  Privacy  Curation
```

## Anatomy of a module

```
app/Domain/Trips/
    Actions/          Single-purpose write operations. One public method: __invoke() or handle().
    Contracts/        Interfaces this module exposes to other modules, and ports it depends on.
    Data/             DTOs / value objects. Readonly. The currency between layers.
    Enums/            Backed enums owned by this module.            → 02-enums.md
    Events/           Domain events this module emits.
    Exceptions/       Domain exceptions.                            → error mapping below
    Models/           Eloquent models owned by this module.
    Policies/         Authorization policies for those models.
    Queries/          Read-side query objects (list, search, aggregate).
    Services/         Orchestration across several actions/queries. Stateless.
```

Not every module needs every directory. Create a directory when you have something to put in it —
an empty `Events/` folder is noise.

### Actions vs Services vs Queries

- **Action** — one write, one intent. `CreateTrip`, `AcceptRecommendation`, `SuppressOpportunity`.
  It owns its transaction. It is the unit of business behaviour and the unit of test.
- **Query** — one read. `ListTripsForUser`, `FindOpportunitiesInTile`. Returns DTOs or paginators,
  never a raw builder that a controller then mutates.
- **Service** — coordinates several actions/queries into a workflow that has a name in the PRD.
  `TripBrain`, `OpportunityScorer`, `SourceRegistry`, `TileCache`, `AgentOrchestrator`,
  `EntityResolver`, `NotificationPolicy`.

If a class does one thing, it is an Action or a Query. Reach for a Service only when you are
genuinely orchestrating.

> **Deviation from PRD §14.1, deliberate:** the PRD sketch shows those named services in a
> top-level `app/Services/`. We place them inside their owning module instead —
> `TripBrain` → `Domain/Trips/Services/TripBrain`, `SourceRegistry` → `Domain/Sources/Services/`,
> `TileCache` → `Domain/Places/Services/`, `OpportunityScorer` → `Domain/Recommendations/Services/`,
> `EntityResolver` → `Domain/Places/Services/`, `AgentOrchestrator` → `Domain/Agent/Services/`,
> `NotificationPolicy` → `Domain/Notifications/Services/`. A top-level `app/Services/` would be a
> second, competing home for logic and would erode the module boundary within a month.
> **There is no `app/Services/` directory in this repo.**

## Cross-module calls

Modules talk to each other **through contracts and DTOs, never through each other's Eloquent
models**.

```php
// ❌ Recommendations reaching into Places' tables.
$place = \App\Domain\Places\Models\Place::with('sources')->find($id);

// ✅ Recommendations depending on an interface Places publishes.
public function __construct(private PlaceLookup $places) {}

$place = $this->places->find($placeId);   // returns a PlaceData DTO
```

- A module's `Contracts/` directory is its public API. Everything else is internal.
- A module's `Models/` are internal. Another module may hold a `place_id`, never a `Place`.
- Bind contracts to implementations in `app/Providers/DomainServiceProvider.php`, grouped by module.
- Depending on a contract you own is fine and often unnecessary — inject the concrete Action.

Two modules that constantly need each other's internals are one module, or the boundary is in the
wrong place. Raise it rather than punching a hole.

## Models

Models live with their module: `app/Domain/Trips/Models/Trip.php`,
namespace `App\Domain\Trips\Models`.

`app/Models/` holds **only `User`**, which the Laravel starter kit owns and which is genuinely
cross-cutting. Do not add to it.

Because models are outside `app/Models/`, Laravel's discovery conventions need one attribute each:

```php
namespace App\Domain\Trips\Models;

use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Policies\TripPolicy;
use Database\Factories\Domain\Trips\TripFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TripFactory::class)]
#[UsePolicy(TripPolicy::class)]
final class Trip extends Model
{
    protected function casts(): array
    {
        return [
            'status'     => TripStatus::class,
            'started_at' => 'immutable_datetime',
        ];
    }
}
```

Factories live at `database/factories/Domain/Trips/TripFactory.php`, namespace
`Database\Factories\Domain\Trips`. See [11-testing.md](11-testing.md).

Models are for persistence and relationships. **No business rules on models** — no
`$trip->recommendNext()`. Query scopes are fine; a scope that encodes a policy decision is not.

## Data (DTOs)

Cross a layer boundary and you cross it with a DTO, not an associative array.

```php
namespace App\Domain\Trips\Data;

final readonly class TripData
{
    public function __construct(
        public string $id,
        public string $userId,
        public TripStatus $status,
        public CarbonImmutable $startsAt,
    ) {}

    public static function fromModel(Trip $trip): self { /* ... */ }
}
```

Rules: `final readonly`, constructor-promoted, typed. No `array $options` bags — if it has three
shapes, it is three DTOs or an enum. A DTO knows how to build itself from a model
(`fromModel`), not how to save itself.

## Exceptions

Domain code throws domain exceptions from `Domain/{Module}/Exceptions/`. It never throws HTTP
exceptions and never returns a response — a domain service does not know it is behind HTTP.

```php
throw new TripNotActive($tripId);
```

Mapping to a status code happens once, in `bootstrap/app.php`'s exception handler.
Do not `abort(404)` inside a domain service.

## Where a feature actually lands

A worked example — "user accepts a recommendation":

| Layer | Class | Responsibility |
|-------|-------|----------------|
| Route | `routes/api.php` | `POST /api/v1/recommendations/{recommendation}/accept` |
| Request | `app/Http/Requests/Api/V1/Recommendations/AcceptRequest.php` | validation, authorization |
| Controller | `app/Http/Controllers/Api/V1/RecommendationController@accept` | calls the action, returns a Resource. ~5 lines. |
| Action | `app/Domain/Feedback/Actions/AcceptRecommendation.php` | the actual behaviour, the transaction, the event |
| Model | `app/Domain/Recommendations/Models/Recommendation.php` | persistence |
| Resource | `app/Http/Resources/Api/V1/RecommendationResource.php` | the wire shape |

The Inertia controller for the same feature calls **the same action**. That is the whole point.

## Checklist

- [ ] No `if` that encodes a business rule sits in a controller or a job.
- [ ] The Action can be tested without an HTTP request.
- [ ] No `use App\Domain\Other\Models\*` outside the owning module.
- [ ] New cross-module dependency has a contract, bound in `DomainServiceProvider`.
- [ ] Model has `#[UseFactory]` and (if authorizable) `#[UsePolicy]`.
