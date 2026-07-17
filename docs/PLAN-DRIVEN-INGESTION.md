# PLAN-DRIVEN INGESTION — search anywhere, ingest ahead of arrival

> **Status: DESIGN (not built).** This is the spec for making the planner search work for
> *any* place on Earth and for ingesting a planned destination *before the traveller gets
> there*, using the days between planning and arriving. It extends VISION §1 from direction
> into design. Read `docs/VISION.md` §1, `docs/PRD.md` §9.2–§9.4, and the E48 code named below
> before implementing.

## 0. The one-paragraph version

Most of the engine already exists (E48). What's missing is an **index to plan against** and a
**trigger that runs ahead of demand**. We add: (1) a **global gazetteer** — a lightweight,
self-hosted index of every place-name on Earth — so the planner can find Kusmark; (2) a
**trip-plan trigger** that hands a newly-anchored trip to the *same* region-derivation engine
that already learns places on arrival, so the area is warming while the trip is still a plan;
and (3) a **cheap-first phase split** so a speculative trip warms the geo-core cheaply and only
pays for the expensive evidence/photo passes when it's actually near or actually opened.

## 1. What already exists (do not rebuild)

The founder dropped a pin in Skellefteå, the app had nothing, and E48 was built to fix exactly
that. It is the engine; this spec bolts a plan-time front-end onto it.

- **`DeriveRegionForPosition`** — snaps an arbitrary point to an **H3 res-5 cell** (~252 km²,
  ~17 km across) and mints an `IngestRegion` for it. Deterministic and self-deduplicating: any
  pin in the cell derives the *same* region with the same key and box list (which is why Kusmark
  and Skellefteå resolve to one region, not two). This is the "dynamic region derivation" VISION
  §4 said wasn't built — it is now.
- **`DerivedRegion`** (model + table) — the persisted record of a cell we've learned or are
  learning, with `requested_by_user_id` / `requested_at`.
- **`LearnAreaIfUnknown`** — the guarded entry point. Asks *does a region already claim this
  ground?* (`RegionCatalog::covering`), *are there already places within reach?*
  (`PlaceDensity::within`), *is it building right now?* (`RegionBuildStatus::isBuilding`), and
  *has this user asked too much today?* (`MAX_REGIONS_PER_USER_PER_DAY = 6`). Only if all clear
  does it build. **All the dedup and abuse guards a plan-time trigger needs already live here.**
- **`FirstLightJob`** — one small Overpass box around the point, on a lane that isn't about to
  fill with the region's fifty boxes. Central Umeå answers in ~4 s.
- **`BuildRegionWorldModelJob`** — the phase chain: `ingest → resolve → evidence → photos →
  warm`, auto-chained, idempotent per `(regionKey, phase, source)`, dispatched **nearest-first**
  (`IngestRegion::boxesNearest`) so the tiles you're standing in land first.
- **`RegionBuildStatus`** — the build lock and phase tracker; the same lock the admin Build
  button takes, so an operator and a traveller can't ingest one city twice.
- **Triggers today:** `LearnAreaOnSessionStart` and `LearnAreaOnPositionMoved` — i.e. **arrival
  and movement**. Never planning.

## 2. The gap, stated precisely

1. **You cannot search a place you have not walked into.** The planner typeahead
   (`LookupPlaces::search` → `places_core`) only knows places we've already ingested. There is
   no global name index, so "Kusmark" and "Skellefteå" return nothing at plan time. E48 needs a
   physical pin; planning needs a *searchable name*.
2. **Ingestion is reactive, not ahead-of-demand.** The region is learned when you *arrive*. The
   trip plan — the one signal that comes with days of lead time — doesn't trigger anything.
3. **A speculative plan would pay full price.** `BuildRegionWorldModelJob` runs the whole chain
   including the slow, rate-limited evidence (Wikipedia) and photos (Commons/Mapillary) passes.
   Firing that for every "maybe someday" trip would gather rich data for regions nobody visits
   and lean hard on free community APIs.

## 3. The two-tier model

Searching Earth and ingesting Earth are different problems. Keep them apart.

| Tier | What it is | Scope | Powers |
|---|---|---|---|
| **Gazetteer** | place *names + coordinates* only | global, loaded once | plan-time **search**; **naming** derived cells |
| **`places_core`** | the detailed, explorable world model | per-cell, on demand | the **feed** — what's actually around you |

The gazetteer is the index; `places_core` is the content. Typing "Kusmark" hits the gazetteer to
get a coordinate to anchor on; that anchor then drives ingestion of the *cell*, which fills
`places_core`. The gazetteer is **never** served as opportunities — it has no hours, no evidence,
no scores. It only answers "where is this named place."

### 3.1 The gazetteer, concretely

- **Source: OSM `place=*` nodes** (`city`, `town`, `village`, `hamlet`, `suburb`, `neighbourhood`,
  plus `place=island`/`locality` for the rural long tail). All-ODbL — one license story with
  `places_core`, and the same OSM pipeline we already run. (GeoNames/CC-BY is the fallback if OSM
  place-node coverage proves thin, at the cost of a second license + attribution line.)
- **Size:** a few million rows worldwide — names, coordinates, `place` rank, `population` where
  tagged, country/admin labels for disambiguation. A one-time bulk load (a planet `place=*`
  extract is small; no full-detail planet import), refreshed occasionally. Lives in its own table
  (`gazetteer_places` or similar), **outside** `places_core` so the ODbL-publishable boundary of
  the core is unchanged.
- **Search:** prefix + trigram fuzzy (the `LookupPlaces::search` pattern), ranked by importance
  (`place` rank × population) so "Paris" the city beats "Paris" the hamlet, with country/region
  shown for disambiguation ("Kusmark, Skellefteå, SE").
- **Naming derived cells for free:** `DeriveRegionForPosition` already notes the cell name is
  "looked up separately, at leisure." The gazetteer *is* that lookup — the nearest populated
  place to the cell centroid names the region ("Kusmark") instead of a coordinate key.

### 3.2 Planner search = gazetteer ∪ places_core

The planner's "Where are you going?" merges two sources: `places_core` (rich, in-covered-regions
— a specific café can be an anchor) and the gazetteer (global — any town). In-region results rank
first (we have detail there); the gazetteer supplies everywhere else. One box, two backings.

## 4. The trip-plan trigger

A new listener — **`LearnAreaOnTripPlanned`** — fires when a trip is created or edited with an
anchor, and calls the **existing** `LearnAreaIfUnknown` with the anchor coordinates. That's it:
the derivation, dedup, per-user cap, first-light, and nearest-first build all come for free. The
only new decisions are *how eagerly* and *in what order*.

### 4.1 Cheap-first (decided)

Do **not** run the full chain on a plan. Split the build:

- **On trip-create (eager, cheap):** `ingest → resolve → warm`. This fills `places_core` (names,
  geometry, categories) and warms the shared tile cache. It's the geo-core — enough for a feed
  that can say "here's what's around," cheaply, and it uses only Overpass (which we already pace).
  The evidence and photo passes are **not** dispatched.
- **Deferred (lazy, expensive): `evidence → photos`.** Triggered when the trip has *earned* the
  cost — whichever comes first:
  - the trip's `planned_start_at`/`departs_at` enters a near-term window (e.g. ≤ 48 h out), via
    the scheduler; or
  - the traveller opens their **first session** in the cell (the E48 arrival path), which
    promotes the region from geo-core-only to fully enriched on the spot.

  Mechanically: `BuildRegionWorldModelJob`'s `resolve → evidence` auto-hop becomes conditional on
  a build **tier** (`geo_core` vs `full`) carried on the job / `DerivedRegion`. A `geo_core` build
  stops after `warm`; promoting to `full` dispatches `evidence` and the tail chains as today. This
  is the one real change to the existing job.

### 4.2 Priority by date

Trips carry `planned_start_at` / `departs_at` (already built). The ingest queue orders by
imminence: a trip today jumps ahead of a trip next month. VISION §1's "which regions have the
most booked user-days in the next 30 days" is the same signal, later, for the curation queue.

### 4.3 Guardrails (mostly already present)

- **Dedup:** `LearnAreaIfUnknown` already refuses a cell that's covered, populated, or building.
  A trip anchored in Stockholm triggers nothing.
- **Abuse / runaway:** the per-user `MAX_REGIONS_PER_USER_PER_DAY` cap already applies (planning
  ten trips across a continent won't queue ten thousand Overpass boxes). Registration is
  allowlisted on top.
- **Cost:** Google stays edge-only at recommendation time — plan-driven ingest spends **no**
  Google. The costs are compute, free-source (Overpass/Wikipedia/Commons) politeness, and
  `places_core` disk growth as coverage expands from pilot-size to many cells. The serial `ingest`
  lane already prevents a region build from stampeding the sources; cheap-first keeps the
  rate-limited passes off speculative cells entirely.
- **Staleness:** ingest-once is fine for the pilot horizon. A re-ingest cadence per cell is a
  later refinement, not v1.

## 5. Graceful arrival

Ingestion has lead time, but someone will always plan a trip an hour before driving there. The
product must degrade honestly, never look broken:

- **On the planned trip:** surface `RegionBuildStatus` — "Preparing this area…" → "Ready." The
  trip page already has room for it (it shows dates and status today).
- **On arrival before ready:** the feed fills in live as boxes land (nearest-first means the
  ground underfoot is first), with an honest "still gathering this area" rather than a blank. If
  only the geo-core is warm (evidence/photos deferred), the feed works on names + geometry and
  thickens as enrichment completes — the E48 promotion path.
- **Honesty about depth (PRD §8.2):** where there's no curated density, say so — "we don't know
  this area deeply yet." Auto-ingested OSM alone is not the finished product, and pretending
  otherwise is PRD risk #1 (VISION §1).

## 6. Licensing

- The **gazetteer** is OSM `place=*` (ODbL) — same license as the core, in its own table, never
  blended into `places_core`. Attribution as OSM already requires.
- `places_core` stays ODbL-only regardless of which cell it covers — plan-driven ingest runs the
  *same* open-data pipeline (OSM/Overture/Wikidata/gov) on a new box. No new licensing surface;
  the ODBL-REVIEW §6 boundary is untouched.
- Anything that spends or persists a new source still owes `docs/legal/ROPA.md` an update — but
  plan-driven ingest introduces no new outbound host or personal-data table beyond what E48 and
  the gazetteer load already use.

## 7. Build phases

Each phase is independently useful and shippable.

1. **Gazetteer + planner search.** ✅ **SHIPPED 2026-07-17** (PRs #98–#101), scoped to Sweden +
   France (~263k rows, ~100 MB, mostly French `hamlet`). `gazetteer_places` + `SearchGazetteer` +
   `PlaceTypeahead` merge into the typeahead; `gazetteer:load SE FR`. *Outcome: you can search and
   anchor any settlement in the loaded countries.* Cell NAMING from the gazetteer (§3.1) is not yet
   wired. **Operational note:** `GazetteerLoader` is a synchronous artisan command and area-clipping
   is slow (Overpass re-resolves the country boundary per tile → France ≈ 30–40 min); a crash loses
   everything after the last tile. Fine for a rare hand-run load. If it becomes a hot path (many
   countries, on demand), **make it a resumable queued job** — one tile per job, re-dispatching
   until done, the way `App\Jobs\Ingest\BackfillPhotosJob` does the photo backfill.
2. **Trip-plan trigger, cheap-first.** `LearnAreaOnTripPlanned` → `LearnAreaIfUnknown`; add the
   `geo_core`/`full` build tier to `BuildRegionWorldModelJob`; dispatch geo-core on trip-create.
   *Outcome: anchoring a trip warms its geo-core ahead of arrival.*
3. **Deferred enrichment + priority.** Scheduler promotes near-term trips (and the first session
   promotes on arrival) to the `full` tier; queue orders by imminence. *Outcome: the expensive
   passes run only where and when they're earned.*
4. **Arrival UX.** Region status on the planned trip; live-fill + honest "still gathering" on the
   feed. *Outcome: planning-to-arrival is seamless and never looks broken.*

## 8. Open decisions

- **Gazetteer source:** OSM `place=*` (one license, recommended) vs GeoNames (easier load, second
  license). Default OSM unless coverage of the rural long tail (hamlets like Kusmark) proves
  inadequate in testing.
- **Deferred-enrichment window:** how many hours before `planned_start_at` the `full` tier fires
  (48 h is a starting guess). Also: should a trip with *no* dates ever auto-promote, or only via
  first-session arrival?
- **Cell size at walking edge:** res-5 (~17 km) is E48's choice; someone at a cell edge has part
  of their reach in an unlearned neighbour. Movement already learns the next cell (E48); confirm
  this is acceptable for *planned* anchors near a boundary, or pre-warm the neighbour.
- **`places_core` growth:** at what coverage does disk/DB size need a plan (partitioning, or
  evicting cold cells that no live trip references)? Not a v1 blocker; name the threshold.
