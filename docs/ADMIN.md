# Travel Companion AI — Admin & Operations Console

| | |
|---|---|
| **Document status** | Design v1.0 · step 1 implemented |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §14.1, §14.3, §15, §16 · [SCORING.md](SCORING.md) §9 · [conventions/01](conventions/01-domain-modules.md), [conventions/04](conventions/04-controllers-and-routing.md) |

---

## 1. Why this document exists

PRD §14.1 sketches "Admin / curation / analytics" as a box in the architecture; §14.3 makes cost
a first-class requirement ("instrument before targeting"); §15 requires every recommendation to
store a full decision trace; §16 requires privacy operations that someone has to actually be able
to perform. All of those need an operator surface. This document specifies it: who may access it,
where its code lives, what its sections are, and in what order they get built.

The governing idea: **the admin console is the read side of instrumentation the architecture
already requires.** It does not introduce new pipeline behaviour; it renders traces, costs, cache
state and source health that conventions/08, /10 and /12 oblige the pipeline to record anyway. The
two exceptions — position emulation (§6) and curation (§5) — are deliberate write surfaces, and
both are specified here before they are built.

## 2. Principles

1. **Admin is a delivery surface, not a domain module.** The twelve modules of PRD §14.1 are fixed
   (conventions/01). Admin controllers are thin Inertia wrappers, exactly like `Web/` and `Api/V1/`
   controllers; each admin feature calls actions and queries in its *owning* module (curation →
   Curation, traces → Recommendations, source health → Sources, emulation → Context, privacy ops →
   Privacy). What has no owning module is *platform* concern, and lives in `app/Admin/` (§4).
2. **Gate on permissions, assign permissions via roles.** Code checks a permission
   (`can:users_manage_roles`), never a role name. Roles are rearrangeable bundles; `superadmin`
   passes every gate via `Gate::before`.
3. **Every operator action is audit-logged.** This is a GDPR-from-day-one product; "who emulated a
   location, who changed whose roles, who viewed which user's trace" must be answerable
   (spatie/laravel-activitylog, §9).
4. **Emulated context is marked, never learned from.** Anything the pipeline produces under
   position emulation carries `context_source = emulated` on its trace and is excluded from
   learning signals, cost-per-trip-hour metrics, and gold traces (§6). Without this, our own
   testing corrupts the metrics this console exists to watch.
5. **Dashboards trail the pipeline.** No admin section is built ahead of the tables it reads
   (§10). The console never becomes speculative UI over data that doesn't exist yet.

## 3. Access model

**Package:** [spatie/laravel-permission](https://spatie.be/docs/laravel-permission/v8/introduction)
v8. `User` uses `HasRoles`. Both roles and permissions are string-backed enums per conventions/02
— `App\Enums\Role` and `App\Enums\Permission` (cross-module by nature: the admin surface spans
modules). The spatie tables are seeded idempotently from the enums by
`Database\Seeders\RolesAndPermissionsSeeder`.

### 3.1 Roles

| Role | Meaning |
|------|---------|
| `admin` | Operator. Read-mostly surfaces: dashboards, users, activity, traces, costs, curation, ops tools. |
| `superadmin` | Owner. Everything, via `Gate::before` — including the dangerous writes: role management, position emulation, privacy operations, scoring configuration. |

Regular users hold no role. Registration stays allowlisted independently
(`ALLOWED_REGISTRATION_EMAILS`, CLAUDE.md) — the allowlist decides who may *register*; roles decide
what an account may *do*. Moving the allowlist into a DB-backed admin page is step-4 scope (§10).

The first superadmin is granted from the CLI: `php artisan user:assign-role <email> superadmin`.

### 3.2 Permissions (v1 set)

| Permission | `admin` | `superadmin` | Guards |
|------------|:-------:|:------------:|--------|
| `admin_access` | ✓ | ✓* | the `/admin` route group as a whole |
| `ops_view` | ✓ | ✓* | Horizon (`viewHorizon` gate) and Pulse (`viewPulse` gate) |
| `users_view` | ✓ | ✓* | user list |
| `users_manage_roles` | — | ✓* | assigning/removing roles |
| `activity_view` | ✓ | ✓* | audit log |

\* superadmin holds nothing directly; `Gate::before` grants all. Future sections add their
permission here when they land: `location_emulate` (superadmin-only), `curation_manage`,
`costs_view`, `traces_view`, `privacy_operate` (superadmin-only), `scoring_configure`
(superadmin-only).

Two hard rules, both enforced in code and tests:

- **An operator cannot modify their own roles** (lockout- and self-escalation-proof; CLI is exempt
  because it already implies server access).
- **Permission names are checked via `can:`** middleware / `$user->can()`, which spatie wires into
  the Gate — no `hasRole()` checks outside the `Gate::before` definition itself.

Sessions are persistent by design (logins are always remembered — `LoginRequest`), so when the
dangerous write surfaces land (role changes exist today; position emulation, privacy operations
and scoring config are coming), put Laravel's `password.confirm` middleware on those routes:
eternal convenience everywhere, a real password check at the moment it matters.

## 4. Where the code lives

Admin is the third HTTP delivery surface beside `Web/` and `Api/V1` (conventions/04). Same
thin-wrapper rules, same layering:

```
routes/admin.php                          required from web.php; middleware ['auth', 'can:admin_access']
app/Http/Controllers/Admin/               thin Inertia controllers (final, resourceful, < 10 lines)
app/Http/Requests/Admin/                  form requests (validation + authorization)
resources/js/pages/admin/                 Inertia pages, shadcn/ui, AppLayout
```

**Product-domain features delegate to their owning module.** The emulation controller calls
`Domain/Context` actions; the curation controllers call `Domain/Curation` actions. No admin-only
business logic ever lands in a controller.

**Platform concerns get `app/Admin/`.** User/role management, audit-log reading and dashboard
composition have no product module and must not invent a thirteenth (conventions/01). They live in
a narrow platform namespace with the same internal anatomy as a domain module:

```
app/Admin/
    Actions/       AssignRole, SyncUserRoles
    Queries/       ListUsers, ListActivity, DashboardStats
    Data/          readonly DTOs (the currency to Inertia props)
    Exceptions/    domain-style exceptions, mapped once in bootstrap/app.php
```

Scope is deliberately tight: **if a feature has an owning domain module, it may not live in
`app/Admin/`.** The dependency is one-way — `App\Admin` may consume domain *contracts*;
`App\Domain` never references `App\Admin`. Both directions, plus transport-agnosticism (no
`Request`/`Inertia` inside `App\Admin`), are enforced by `tests/Arch/ConventionsTest.php`.

## 5. The console, section by section

| Section | Route | Permission | Logic lives in | Phase |
|---------|-------|-----------|----------------|-------|
| Dashboard | `/admin` | `admin_access` | `App\Admin\Queries\DashboardStats` | **built** |
| Users & roles | `/admin/users` | `users_view` / `users_manage_roles` | `App\Admin` | **built** |
| Activity (audit log) | `/admin/activity` | `activity_view` | `App\Admin\Queries\ListActivity` | **built** |
| Horizon (queues, failed jobs) | `/horizon` (link) | `ops_view` | Laravel Horizon | **built** |
| Pulse (exceptions, slow queries, usage) | `/pulse` (link) | `ops_view` | Laravel Pulse | **built** |
| Position emulation map | `/admin/emulation` | `location_emulate` | `Domain/Context` | step 2 (§6) |
| Source health | `/admin/sources` | `admin_access` | `Domain/Sources` | step 2 (§7.2) |
| Pipeline events | `/admin/events` | `admin_access` | log-shaped table (§8) | step 2 |
| Cost dashboard | `/admin/costs` | `costs_view` | cost records, conventions/08 | step 3 (§7.1) |
| Trace inspector | `/admin/traces/{recommendation}` | `traces_view` | `Domain/Recommendations` | step 3 |
| Curation | `/admin/curation` | `curation_manage` | `Domain/Curation` | step 4 |
| Tile cache overlay | on the emulation map | `admin_access` | `Domain/Places` (`TileCache`) | step 4 |
| Registration allowlist | `/admin/users` (tab) | `users_manage_roles` | `App\Admin` | step 4 |
| Privacy operations | `/admin/privacy` | `privacy_operate` | `Domain/Privacy` | step 4 |
| Scoring config / replayer | `/admin/scoring` | `scoring_configure` | `Domain/Recommendations` | step 4, with the replayer (PRD §15.2) |

## 6. Position emulation (step 2 — specified now, built with the Context module)

The single most valuable dev tool in the console, and one design decision makes or breaks it:
**the emulated position enters through the same context-ingestion boundary as a real one.**

- `Domain/Context` owns a `ContextResolver`. When resolving the current context for a user it
  first consults a per-user **location override** (Redis, TTL'd, key prefixed `travel_`); only
  then falls back to the device-reported signal. Tile resolution, scouts, caching, scoring and
  delivery all see the override identically to a real position — we test the actual pipeline, not
  a lookalike.
- Every context event records `context_source` (`device` | `emulated`) — an enum per
  conventions/02 — and the value propagates onto the decision trace of everything downstream
  (conventions/03 required-columns spirit). Learning, cost metrics and gold traces filter it out.
- The map is **MapLibre GL + OSM raster tiles** — license-consistent with the ODbL world model,
  React-native, no API key. The admin drops a pin (single position, TTL'd) or draws a **path** and
  plays it back as a timed sequence of context events. Path playback is deliberately the
  interactive twin of the trip replayer (PRD §15.2): the replayer runs *recorded* traces headlessly;
  this map authors and steps through *synthetic* ones. They share the ingestion entry point.
- Permission `location_emulate` is superadmin-only, every override set/clear is audit-logged, and
  an active override is visibly bannered in the admin UI (an operator forgetting an override on is
  a confusing-bug factory).

## 7. Costs & source health

### 7.1 Cost dashboard (step 3)

Reads the per-recommendation cost records that conventions/08 and /10 already require ("every
external API call and every LLM call is logged with its cost against the recommendation that
needed it"). Views, in priority order:

1. **Cost per active trip-hour** — the PRD §14.3 budget metric. Instrument first, then set the
   target (PRD open question 4).
2. **Breakdown by source API and by LLM model tier**, over time.
3. **Cost-per-recommendation distribution** — a €0.40 recommendation is a bug (conventions/10).
4. **Cache hit rate per source** — "a product metric, not an ops metric" (conventions/12); the
   number that says whether the shared-tile principle is working.
5. **Circuit-breaker states** from `SourceRegistry`.

Emulated-context costs are shown, but flagged and excluded from the trip-hour metric (§2.4).

### 7.2 Source health (step 2)

Per adapter, from `SourceRegistry` + `scout_runs` (PRD §14.2 observability tables): license class,
adapter version, TTL, last successful scout, error rate, rate-limit headroom, circuit breaker
state. When a region suddenly has no opportunities, this page answers why in ten seconds.

## 8. Errors & warnings

Buy, don't build, for anything generic:

- **Horizon** (installed) — queues, failed jobs, throughput. Linked from the admin nav, gated by
  `viewHorizon` → `ops_view`.
- **Pulse** (installed) — exceptions, slow queries, slow requests, usage. Linked from the admin
  nav, gated by `viewPulse` → `ops_view`. Disabled in tests (`PULSE_ENABLED=false`).

Build custom only the **pipeline events feed** (step 2): product-level warnings no generic tool
can know about — scout returned zero results for a hot tile, LLM output failed validation, a
coverage-honesty flag (PRD §15.3), circuit breaker tripped, licensing-boundary check failed. This
is a log-shaped append-only table (`bigIncrements`, conventions/03), written by the pipeline
wherever those conditions are detected, with severity + module + JSON payload, rendered as a
filterable feed at `/admin/events`. Nothing in the pipeline *reads* it — it is for humans.

## 9. Audit log

spatie/laravel-activitylog v5. Rules:

- Every `App\Admin` action and every domain action invoked *from the admin surface* logs: causer
  (the operator), subject, and a structured `properties` diff. Role changes log old and new role
  sets.
- Reading it requires `activity_view`; it is rendered at `/admin/activity`.
- The audit log is append-only from the application's perspective; there is no admin UI to edit or
  delete entries. Retention/cleanup (`activitylog:clean`) is a deliberate decision to take with
  the DPIA (PRD §16), not a default.

## 10. Build order & status

| Step | Contents | Status |
|------|----------|--------|
| **1** | permission + roles + `Gate::before`, `/admin` shell, dashboard, users & role management, activity log, Horizon/Pulse gates + links, CLI role grant, seeder, arch rules, tests | **done** |
| **2** | with the first pipeline code: position emulation (§6), source health (§7.2), pipeline events (§8) | — |
| **3** | with the first recommendations: trace inspector, cost dashboard (§7.1) | — |
| **4** | steady state: curation UI, tile-cache overlay, DB-backed registration allowlist, privacy operations, scoring config + replayer UI | — |

Each step trails the pipeline data it reads (§2.5). Sections land with their owning module's
schema, never before.

## 11. Testing

Per conventions/11, and enforced in CI:

- **Authorization is the product here.** For every admin route: guest → login redirect, regular
  user → 403, `admin` → 200, and permission-specific denials (`admin` cannot manage roles).
- **Actions tested without HTTP** — `SyncUserRoles` behaviour, the self-modification guard, audit
  entries written.
- **Arch rules** (`tests/Arch/ConventionsTest.php`): `App\Admin` is transport-agnostic; `App\Domain`
  never uses `App\Admin`; `App\Admin` never touches another module's `Models`/`Actions`/`Queries`
  internals; strict types, readonly DTOs, interface contracts — same shape as the domain rules.
- **Enum parity**: `Role` and `Permission` cases match `resources/js/types/enums.ts`
  (conventions/02).
- Position emulation, once built, must have the test that **emulated context never lands in
  learning signals or cost metrics** — that is a CLAUDE.md-grade invariant for this console.
