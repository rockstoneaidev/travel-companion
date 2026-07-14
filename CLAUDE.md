# CLAUDE.md

## What this project is

Travel Companion AI — an agentic travel companion that proactively surfaces contextual "opportunities" (not places) to travelers. Laravel modular monolith backend + Inertia/React frontend, PostgreSQL/PostGIS/pgvector, Redis/Horizon. The app is **scaffolded** (auth, CI, deploy) but feature work has not started; `docs/` is authoritative for what to build.

Read before designing or implementing anything:

- `docs/PRD.md` — product requirements, architecture, phasing. The section numbers below refer to this file unless noted.
- `docs/DATA-SOURCES.md` — data source catalog, licensing classes, Regional Knowledge Packs.
- `docs/ODBL-REVIEW.md` — ODbL analysis; defines the `places_core` database boundary.
- `docs/TAXONOMY.md` — the categorisation taxonomy (Type axis + appeal facets); load-bearing for onboarding, scoring, and learning. Implemented as enums per `conventions/02`.
- `docs/SCORING.md` — the scoring model: every PRD §11 sub-score's v1 formula, constants, penalties, cold-start weight interpolation, and feed selection. Authoritative for implementing ranking.
- `docs/ADMIN.md` — the admin & operations console: roles/permissions model, the `app/Admin/` platform namespace, section map, position-emulation design. Authoritative for anything under `/admin`.
- `docs/COST.md` — the cost model & ledger: `cost_events` schema, versioned pricing config, spend kill-switch, unit-economics model, admin cost UI (extends ADMIN §7.1), GDPR handling of cost data, the Phase 3 chat seam. Authoritative for anything that spends or records money. Epics E24/E25.
- `docs/ENTITY-RESOLUTION.md` — the v1 matching/merge algorithm behind the canonical `places` table (explicit-ID joins → blocked fuzzy matching → survivorship; `resolver_version`).
- `docs/CURATION.md` — curated layer + Regional Knowledge Packs: schema, LLM-draft→ground→review pipeline, and the decided pack plan (Stockholm test + France-trip corridor).
- `docs/ONBOARDING.md` — the taste-calibration content (9 facet-separating pairs + 2 practical questions; `calibration_version`).
- `docs/design/DESIGN.md` + `docs/design/SCREENS.md` — the UI design system (tokens, type, component anatomy, voice) and per-screen build specs with API bindings. Authoritative for all UI work; source mockups in `../Brand/Travel companion brand exploration/`. Naming: the design system has **no codename** — components live in `resources/js/components/app/` and the market-facing name is **"Travel Companion"** (interim, via `APP_NAME` — never hard-code it) until a final brand name is decided.
- `docs/DPIA.md` — the Art. 35 impact assessment (DRAFT). Read §3.2 before touching the taste profile: the learned `spiritual` / `religious_sacred` weights are arguably Art. 9 data by inference, and explicit consent gates the learner.
- `docs/legal/` — the GDPR compliance set, all grounded in the code rather than in the DPIA (which is how they found two things the DPIA had wrong): `ROPA.md` (Art. 30 record — the authoritative list of **every** table holding personal data and **every** outbound recipient; its §9 is the open-findings register), `CONSENT.md` (the exact on-screen wording of every consent, archived per version — Art. 7(1) requires demonstrating *what was agreed to*), `PRIVACY-NOTICE.md` and `BREACH-PROCEDURE.md`, `PROCESSORS.md` + `dpa/` (what the controller must go and sign; no LLM can close these). **If you add a table with personal data, or an `Http::` call to a new host, `ROPA.md` is wrong until you update it.**
- `docs/SERVER-DEPLOYMENT.md` — staging server layout, shared infra, deploy pipeline.
- `docs/EPICS.md` — the work plan: Phase 1 (M1–M3) and Phase 2 (M4–M6, epics E28–E45 on GitHub; entry gate **lifted 2026-07-14** — #46 the living feed comes first, #19 pilot metrics deferred to M6). GitHub milestones, epic index (issue #n = epic En), dependency spines, cut lines. Pick work from here; specs stay authoritative for *how*.
- `docs/VISION.md` — direction beyond Phase 1 (trip-plan-driven ingestion, the opportunity archive, the future content/SEO surface). Direction, not spec: nothing in it overrides PRD phasing, and only the archive (§2) is implemented.
- `docs/conventions/` — **how the code is shaped.** Read `docs/conventions/01-domain-modules.md` before writing any code, then the document matching what you're touching (enums, migrations, controllers, jobs, source adapters, LLM calls, testing, caching). These are binding; flag conflicts rather than deviating.

**Fast lookup — knowledge graph (`graphify-out/`):** the whole repo (code + all docs above) is indexed as a queryable knowledge graph in `graphify-out/graph.json` (946 nodes / 1,531 edges, community-clustered). Any LLM/agent should use it to *find* things before grepping or reading whole docs: if the graphify skill is available, run `/graphify query "<question>"`; otherwise read `graphify-out/GRAPH_REPORT.md` (community hubs = a table of contents for the repo) or traverse `graphify-out/graph.json` directly. Answers cite `source_location`, so use it to jump to the right doc section rather than as a substitute for reading it — the docs remain authoritative. The directory is gitignored (generated artifact): refresh it after large doc/code changes with `/graphify --update`, or build it with `/graphify .` if it's missing on your checkout.

## Stack & tooling

- **Backend:** Laravel 13, PHP 8.5. Auth via the Laravel React starter kit (Inertia). API tokens via Sanctum. Queues via Horizon. Lint: Pint. Tests: Pest 4 (on PHPUnit 12), run against real PostgreSQL/PostGIS/pgvector — SQLite cannot host the geo/vector columns this product is built on. `tests/Arch/ConventionsTest.php` enforces `docs/conventions/` in CI. See `docs/conventions/11-testing.md`.
- **Frontend:** React 19 + TypeScript, Inertia 2, Vite 8, Tailwind 4, shadcn/ui (in `resources/js/components/ui`). Lint: ESLint; format: Prettier.
- **Data:** PostgreSQL 18 + PostGIS + pgvector (custom image, `deployment/docker/postgres/`). Redis (shared on staging — keys are prefixed `travel_`).
- **Commands:** `composer run dev` (all-in-one local), `composer test`, `npm run {lint,typecheck,format:check}`, `docker compose up --build`.
- **Local dev runs in Docker — run artisan in the container.** `docker-compose.yml` runs the stack: `app` (PHP + Vite, ports 80/5173), `postgres`, `redis`, `adminer` (8080). Host PHP has **no phpredis extension** and `.env` points cache/queue/session at Redis, so any artisan or composer command that touches Redis or the runtime app state (`migrate`, `db:seed`, `tinker`, `horizon`, `cache:*`, `queue:*`) must run inside the container: `./vendor/bin/sail artisan migrate` or equivalently `docker compose exec app php artisan migrate`. Sail targets this custom compose file because `.env` sets `APP_SERVICE=app` and `APP_USER=root` — keep both. Fine on the host: `composer test` (phpunit.xml forces array cache + sync queue; the pgsql driver is present), Pint, and all `npm run` scripts. Tests also run in-container via `./vendor/bin/sail pest`.

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
- Docs style: keep PRD/DATA-SOURCES/ODBL-REVIEW/TAXONOMY/SCORING/ENTITY-RESOLUTION/CURATION/ONBOARDING cross-references intact when editing any of them; they link to each other's section numbers.
- Decisions log (2026-07-11): launch = Stockholm test region + France-trip corridor pilot, Jul 27–Aug 7 (PRD §8.0) · client = single responsive Inertia PWA (PRD §13.1) · H3 res 8 (conventions/12) · travel time = estimator gate + edge routing for served items only (PRD §10) · LLM = Gemini behind the LlmClient port, cheap=flash-lite / capable=3.5-flash (PRD Appendix A).
- Decisions log (2026-07-13): expired opportunities are **archived, never plain-deleted** — the nightly reaper moves the license-storable subset of time-bound kinds into `archived_opportunities` before deleting; each `SourceDescriptor` carries an `archivable` flag ("may be kept indefinitely", distinct from TTL) that gates evidence archiving per row (VISION.md §2, DATA-SOURCES §1.1). The archive is write-only: nothing serves from it.
- Decisions log (2026-07-14, #28): the **Phase 2 mobile stack is React Native via Expo** (New Architecture, TypeScript), with a mature native background-geolocation SDK, in a **separate repo** consuming `/api/v1` + Sanctum (share tokens + API types, never components). Rationale in PRD §13.1: the hard part is background location, and native would mean writing the most App-Store-review-sensitive code twice; the escape hatch is a native module, not a rewrite. Still owed: measured battery drain on a real handset (a number to record, not a fork in the road).
- Decisions log (2026-07-14, #32): **Trip Mode runs on consent (Art. 6(1)(a)), per trip** — not the 6(1)(b) that covers foreground location — because the product is complete without it, so a "no" is genuinely free. Words the user actually reads: `docs/legal/CONSENT.md` §2A (C3). Assessment: `docs/DPIA.md` rev 2 (§3.4 the risk, §5.9 the five controls). The consent bullets are printable **only because the server enforces them** (`RecordTripContext`, `NotificationPolicy`) — if you weaken a gate, the consent text becomes a misrepresentation, so change both or neither.
- Decisions log (2026-07-14): the **Phase 2 entry gate is lifted** — founder deems Stockholm field testing sufficient; Phase 2 epics (#28–#45) may start, #19 (pilot exit metrics) moves to M6 (deferred, not dropped). **#46 (the living feed: move re-anchor at pull time, explicit fresh-picks refresh, dismiss backfill — all three) comes first**: it is Phase 1's §8.1 loop working as intended, not Phase 2 machinery. Phase 1's *scope* discipline (constraint 5: foreground-only, pull-based web client) still applies to the existing PWA — proactivity lands via the Phase 2 epics, not by back-porting background behavior into the web client.
