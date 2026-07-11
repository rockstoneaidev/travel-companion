# 11 — Testing

## Framework

**PHPUnit 12, class-based.** Not Pest — it is not installed, and the starter kit's leftover
`tests/Pest.php` is dead code that should be deleted rather than followed.

```php
namespace Tests\Feature\Trips;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_trip_for_the_authenticated_user(): void
    {
        // ...
    }
}
```

Test method names are sentences: `test_it_rejects_a_trip_that_ends_before_it_starts`. A test name
that reads as a specification is documentation; `testStore2` is not.

## The database: PostgreSQL, not SQLite

**Decided 2026-07-11.** The test suite runs against real PostgreSQL + PostGIS + pgvector — the same
custom image as development (`deployment/docker/postgres/`).

The reason is not purity. The world model *is* the product: geography columns, spatial indexes, H3
tiles, vector similarity, `ilike`, JSONB. SQLite cannot host a `geography(Point, 4326)` column or a
`vector(1536)` column at all, which means the interesting half of this codebase would be
structurally untestable, and every migration would have to be written twice — once for real, once
for a dialect that will never run in production.

The cost is a slower suite and a required service in CI. That is the correct trade for this product.

> This supersedes the "in-memory SQLite" line in CLAUDE.md and the `DB_CONNECTION=sqlite` entry in
> `phpunit.xml`. Both must be updated.

Practically:
- CI runs the Postgres service container; `phpunit.xml` points at a dedicated test database.
- `RefreshDatabase` wraps each test in a transaction. Migrate once, not per test.
- Pure unit tests that touch no database (normalizers, scorers, enums, DTOs) do not use
  `RefreshDatabase` and stay fast.

## What to test, by layer

| Layer | Test type | What you assert |
|-------|-----------|-----------------|
| **Actions** (`Domain/*/Actions`) | Feature test, no HTTP | The behaviour. This is where business rules are actually verified. |
| **Queries** | Feature test | Filtering, sorting, pagination, and **no N+1**. |
| **Adapters** (`normalize()`) | Unit test with recorded fixtures | Raw source JSON → candidates. No network. |
| **Scorers / policies** | Unit test | Deterministic in, deterministic out. Sub-scores are asserted individually. |
| **Enums / DTOs** | Unit test | Only where there is real logic (`label()`, `column()`, transitions). |
| **Controllers** | HTTP feature test | Status code, authorization, response shape. Not business rules — those are the Action's tests. |
| **Jobs** | Feature test | Idempotency: **run it twice, assert the same end state.** |

The most valuable tests in this repo will be **Action tests** and **adapter normalization tests**.
An HTTP test that re-asserts what the Action test already covers is duplicated effort with worse
error messages.

## Factories

`database/factories/Domain/Trips/TripFactory.php`, namespace `Database\Factories\Domain\Trips`,
wired to the model with `#[UseFactory]` ([01](01-domain-modules.md)).

- States for meaningful variants: `->active()`, `->expired()`, `->withSegments(3)`.
- A factory produces a **valid** row. If a factory can produce an invalid entity, the invariant is
  missing from the domain.
- Geo factories produce real coordinates in the launch region, not `(0, 0)` — null island passes
  tests and hides bugs.

## What must be tested, non-negotiably

These map to the constraints in CLAUDE.md. Each is a place where a bug is not a bug but an incident:

1. **The licensing boundary.** A test that attempts to persist an `EdgeOnly`-sourced candidate into
   `places_core` and asserts it **throws**. Plus the CI check that no Google-derived value exists in
   the core. ([03](03-migrations-and-schema.md), [09](09-source-adapters.md))
2. **LLM factuality.** Given an evidence bundle containing no opening hours, the generated text
   contains no opening hours. ([10](10-llm-usage.md))
3. **Job idempotency.** Dispatch twice, assert one outcome, assert the paid API was called once.
4. **Authorization.** For every trip-scoped endpoint: another user's trip returns 403/404. Location
   data is the most sensitive thing we hold.
5. **Privacy.** Sensitive-zone suppression works; trip deletion actually deletes the children
   (PRD §16).
6. **Enum parity.** PHP enum cases match `resources/js/types/enums.ts` ([02](02-enums.md)).

## External calls

**No test hits the network.** Ever.

- `Http::fake()` for source adapters, with recorded fixtures under `tests/Fixtures/Sources/`.
- The `LlmClient` contract is bound to a fake that returns canned structured output. This is why
  it is a contract ([10](10-llm-usage.md)).
- `Queue::fake()` when asserting a job was dispatched; a real queue when asserting what the job
  *does*.
- A test that would spend money if a fake were missing should assert the fake was used.

## Gold traces

Once the trip replayer exists (PRD §15.2 — build it early), the ranking and policy layers get a
**regression suite of gold traces**: recorded real context-event traces with expected outcomes.
Pipeline changes are checked against them. This is the mechanism that makes it possible to change
scoring without guessing, and CLAUDE.md names it a first-class dev tool.

## Running

```bash
composer test                 # full suite
php artisan test --filter=CreateTripTest
npm run typecheck && npm run lint
```

Green suite, `pint`, `eslint`, `typecheck` — all four before a PR.

## Checklist

- [ ] The Action has a test that does not go through HTTP.
- [ ] The job is asserted idempotent.
- [ ] No network. `Http::fake()` / fake `LlmClient`.
- [ ] Another user cannot read the resource.
- [ ] Factory produces valid, realistically-located data.
- [ ] Pagination test asserts the page cap and the total.
