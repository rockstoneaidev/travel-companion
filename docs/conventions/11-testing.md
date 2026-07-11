# 11 — Testing

## Framework

**Pest 4**, running on PHPUnit 12. Pest is a DSL and runner on top of PHPUnit, not a separate
engine — PHPUnit class-based tests still execute unchanged, and the starter kit's original tests do
exactly that. Write new tests in Pest style; convert old ones only when you touch them.

```php
<?php

declare(strict_types=1);

use App\Domain\Trips\Actions\CreateTrip;

it('rejects a trip that ends before it starts', function () {
    expect(fn () => app(CreateTrip::class)(tripData(endsOn: '2026-01-01', startsOn: '2026-06-01')))
        ->toThrow(InvalidTripDates::class);
});
```

Test names are sentences. `it('suppresses opportunities inside a sensitive zone')` is a
specification; `testStore2` is not.

`tests/Pest.php` binds `TestCase` + `RefreshDatabase` to the `Feature` suite. Unit tests get
neither — **if a "unit" test needs the database, it is a feature test.**

## Architecture tests — the reason we're on Pest

`tests/Arch/ConventionsTest.php` enforces the rules in this directory **in CI**, so they are not
conventions a tired reviewer has to remember. This is the highest-value test file in the repo.

```php
arch('domain code is transport-agnostic')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate\Http\Request', 'Inertia\Inertia', 'abort', 'response']);

arch('Trips does not reach into another module\'s internals')
    ->expect('App\Domain\Trips')
    ->not->toUse(['App\Domain\Places\Models', 'App\Domain\Places\Actions']);
```

Currently enforced: the module boundary ([01](01-domain-modules.md)) for all twelve modules,
domain code being transport-agnostic, controllers and jobs not touching the database directly, jobs
implementing `ShouldQueue`, enums being string-backed ([02](02-enums.md)), contracts being
interfaces, DTOs being readonly, `declare(strict_types=1)` in the domain, no `env()` outside config,
and no stray `dd()`/`dump()`.

Two things to know:

- Most of `app/Domain/` is still empty, and arch expectations over an empty namespace **pass
  vacuously**. The rules are dormant, not decorative — they activate the moment the first class
  lands in a module. Do not delete a rule because it currently checks nothing.
- **Adding a convention to `docs/conventions/` means asking whether it can be an `arch()` rule.**
  If it can, it should be.

## The database: PostgreSQL, not SQLite

**Decided 2026-07-11.** The suite runs against real PostgreSQL + PostGIS + pgvector — the same
custom image as development (`deployment/docker/postgres/`).

The reason is not purity. The world model *is* the product: geography columns, spatial indexes, H3
tiles, vector similarity, `ilike`, JSONB. SQLite cannot host a `geography(Point, 4326)` or a
`vector(1536)` column at all, so the interesting half of this codebase would be structurally
untestable, and every migration would have to be written twice — once for real, once for a dialect
that never runs in production.

The cost is a required service in CI. That is the correct trade for this product.

- `phpunit.xml` forces `DB_DATABASE=travel_companion_test`; host, port and credentials are inherited
  from your `.env`, so it works on any developer's machine.
- **Create the database once locally**, with the extensions:
  ```bash
  docker compose exec postgres psql -U "$DB_USERNAME" -d postgres -c "CREATE DATABASE travel_companion_test"
  docker compose exec postgres psql -U "$DB_USERNAME" -d travel_companion_test \
      -c "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS vector;"
  ```
  *(When a migration owns `CREATE EXTENSION`, the second command goes away. It should.)*
- `RefreshDatabase` wraps each test in a transaction. Pure unit tests (normalizers, scorers, enums,
  DTOs) touch no database and stay fast.

## What to test, by layer

| Layer | Test type | What you assert |
|-------|-----------|-----------------|
| **Actions** (`Domain/*/Actions`) | Feature test, no HTTP | The behaviour. Business rules are verified here. |
| **Queries** | Feature test | Filtering, sorting, pagination, and **no N+1**. |
| **Adapters** (`normalize()`) | Unit test with recorded fixtures | Raw source JSON → candidates. No network. |
| **Scorers / policies** | Unit test | Deterministic in, deterministic out. Sub-scores asserted individually. |
| **Enums / DTOs** | Unit test | Only where there is real logic (`label()`, `column()`, transitions). |
| **Controllers** | HTTP feature test | Status code, authorization, response shape. Not business rules — those are the Action's. |
| **Jobs** | Feature test | Idempotency: **run it twice, assert the same end state.** |

The most valuable tests here will be **Action tests** and **adapter normalization tests**. An HTTP
test re-asserting what an Action test already covers is duplicated effort with worse failure output.

Pest **datasets** fit the fixture-heavy work in this repo well — one normalization test over every
recorded payload for a source:

```php
it('normalizes every recorded Overpass payload', function (string $fixture) {
    $candidates = (new OverpassAdapter)->normalize(fixture($fixture));
    expect($candidates)->each->toBeInstanceOf(Candidate::class);
})->with(fixtures('Sources/Overpass'));
```

## Factories

`database/factories/Domain/Trips/TripFactory.php`, namespace `Database\Factories\Domain\Trips`,
wired to the model with `#[UseFactory]` ([01](01-domain-modules.md)).

- States for meaningful variants: `->active()`, `->expired()`, `->withSegments(3)`.
- A factory produces a **valid** row. If it can produce an invalid entity, the invariant is missing
  from the domain.
- Geo factories produce real coordinates in the launch region, not `(0, 0)` — null island passes
  tests and hides bugs.

## What must be tested, non-negotiably

Each maps to a constraint in CLAUDE.md. Each is a place where a bug is an incident, not a defect:

1. **The licensing boundary.** Persisting an `EdgeOnly`-sourced candidate into `places_core`
   **throws**. Plus the CI check that no Google-derived value reached the core.
   ([03](03-migrations-and-schema.md), [09](09-source-adapters.md))
2. **LLM factuality.** Given an evidence bundle with no opening hours, the generated text contains
   no opening hours. ([10](10-llm-usage.md))
3. **Job idempotency.** Dispatch twice, assert one outcome, assert the paid API was called once.
4. **Authorization.** For every trip-scoped endpoint: another user's trip returns 403/404.
5. **Privacy.** Sensitive-zone suppression works; trip deletion actually deletes the children
   (PRD §16).
6. **Enum parity.** PHP enum cases match `resources/js/types/enums.ts` ([02](02-enums.md)).

## External calls

**No test hits the network. Ever.**

- `Http::fake()` for source adapters, with recorded fixtures under `tests/Fixtures/Sources/`.
- The `LlmClient` contract is bound to a fake returning canned structured output. That is why it is
  a contract ([10](10-llm-usage.md)).
- `Queue::fake()` when asserting a job was *dispatched*; a real queue when asserting what it *does*.
- A test that would spend money if a fake were missing should assert the fake was used.

## Gold traces

Once the trip replayer exists (PRD §15.2 — build it early), ranking and policy get a **regression
suite of gold traces**: recorded real context-event traces with expected outcomes. Pipeline changes
are checked against them. This is what makes it possible to change scoring without guessing, and
CLAUDE.md names it a first-class dev tool.

## Running

```bash
composer test                      # everything (Unit + Feature + Arch)
vendor/bin/pest --testsuite=Arch   # just the convention rules — fast, run it often
vendor/bin/pest --filter=CreateTrip
vendor/bin/pest --parallel
npm run typecheck && npm run lint
```

Green suite, Pint, ESLint, typecheck — all four before a PR.

## Checklist

- [ ] The Action has a test that does not go through HTTP.
- [ ] The job is asserted idempotent.
- [ ] No network. `Http::fake()` / fake `LlmClient`.
- [ ] Another user cannot read the resource.
- [ ] Factory produces valid, realistically-located data.
- [ ] New convention → asked whether it can be an `arch()` rule.
