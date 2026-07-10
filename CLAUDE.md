# CLAUDE.md

## What this project is

Travel Companion AI — an agentic travel companion that proactively surfaces contextual "opportunities" (not places) to travelers. Laravel modular monolith backend + Inertia/React frontend, PostgreSQL/PostGIS/pgvector, Redis/Horizon. The app is **scaffolded** (auth, CI, deploy) but feature work has not started; `docs/` is authoritative for what to build.

Read before designing or implementing anything:

- `docs/PRD.md` — product requirements, architecture, phasing. The section numbers below refer to this file unless noted.
- `docs/DATA-SOURCES.md` — data source catalog, licensing classes, Regional Knowledge Packs.
- `docs/ODBL-REVIEW.md` — ODbL analysis; defines the `places_core` database boundary.
- `docs/SERVER-DEPLOYMENT.md` — staging server layout, shared infra, deploy pipeline.

## Stack & tooling

- **Backend:** Laravel 13, PHP 8.5. Auth via the Laravel React starter kit (Inertia). API tokens via Sanctum. Queues via Horizon. Lint: Pint. Tests: PHPUnit (in-memory SQLite).
- **Frontend:** React 19 + TypeScript, Inertia 2, Vite 8, Tailwind 4, shadcn/ui (in `resources/js/components/ui`). Lint: ESLint; format: Prettier.
- **Data:** PostgreSQL 18 + PostGIS + pgvector (custom image, `deployment/docker/postgres/`). Redis (shared on staging — keys are prefixed `travel_`).
- **Commands:** `composer run dev` (all-in-one local), `composer test`, `npm run {lint,typecheck,format:check}`, `docker compose up --build`.

## Conventions & rules specific to this repo

- **API-first boundary (load-bearing):** Inertia is the Phase-1 web delivery layer, but it is NOT an API. All product logic lives in `app/Domain/*` services; Inertia controllers AND the versioned JSON API (`routes/api.php`, `/api/v1`, Sanctum) are thin wrappers over those services. The Phase-2 mobile client must be *additive*, never a backend rewrite. Do not put business logic in Inertia controllers.
- **Registration is allowlisted** while pre-launch: `config('auth.allowed_registration_emails')` from `ALLOWED_REGISTRATION_EMAILS` (empty = open). Enforced in `RegisteredUserController`.
- Behind Traefik (TLS terminated at proxy): `trustProxies(at: '*')` is set in `bootstrap/app.php` — keep it.
- `config/database.php` uses PHP 8.5's `Pdo\Mysql::` constant (not the deprecated `PDO::MYSQL_*`).

## Non-negotiable constraints

These are decided. Do not re-litigate them in implementation; flag explicitly if a task appears to conflict.

1. **Licensing boundary (ODBL-REVIEW §6):** the conflated geo-core (`places_core`: names, geometry, categories) contains ONLY ODbL-compatible open data (OSM, Overture, Wikidata, gov open data) and is treated as publishable under ODbL. All proprietary value (curated content, packs, scores, user signals, opportunities) lives in separate tables keyed by `place_id`, each data type single-source, never blended into the core.
2. **Google Places data is never persisted** into any world-model table — edge-only, fetched at enrichment/recommendation time. Store `place_id` only. This is both Google ToS and ODbL compliance.
3. **The LLM is never a source of facts** (opening hours, prices, distances, existence). It generates only from stored evidence bundles; every generation records `prompt_version`.
4. **Deterministic policy gates all delivery.** The LLM never decides when to interrupt. Notification budget: max 3 proactive pushes/day (Phase 2).
5. **Phasing is strict (PRD §8):** Phase 1 is pull-based and foreground-only — no background location, no geofences, no push, no Reverb, no embedding-based taste model. Don't build Phase 2 machinery early.
6. **Shared geo-tile caching (PRD §9.3):** scout results cache per H3 tile, shared across users; personalization happens only at ranking time.
7. **Version everything (PRD §15):** scoring_model_version, prompt_version, source_adapter_version, notification_policy_version. Every recommendation stores its full decision trace and all sub-scores.
8. **Privacy is architectural (PRD §16):** sensitive-zone suppression, short raw-location retention, trip-level deletion. EU/GDPR from day one; DPIA before launch.

## Conventions (when implementation starts)

- Laravel domain modules under `app/Domain/` per PRD §14.1 (Trips, Context, Profiles, Opportunities, Places, Recommendations, Sources, Agent, Feedback, Privacy, Curation); jobs under `app/Jobs/{Scouts,Enrichment,Ranking,Delivery}/`.
- Every source adapter implements the `ScoutSource` contract and carries license metadata in `SourceRegistry` (what may be stored, TTL, attribution).
- `places` (canonical, stable) vs `opportunities` (ephemeral, TTL'd) stay strictly separated.
- The **trip replayer** (PRD §15.2) is a first-class dev tool — pipeline changes should be checked against gold traces once it exists.

## Repo/workflow notes

- GitHub remote: `rockstoneaidev/travel-companion` (HTTPS). Auth goes through `gh`; the active `gh` account must be **rockstoneaidev** (`gh auth switch --user rockstoneaidev` if pushes 403 as another account).
- Repo-local `user.email` is `rockstoneaidev@gmail.com` — keep it for commit attribution.
- Docs style: keep PRD/DATA-SOURCES/ODBL-REVIEW cross-references intact when editing any of the three; they link to each other's section numbers.
