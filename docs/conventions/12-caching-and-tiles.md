# 12 — Caching & tile keys

Caching is not an optimization to add later in this product. **Shared geo-tile caching is a core
design principle** (PRD §9.3): scouting Beaune once must serve every user in Beaune. It is the
primary cost-control and latency mechanism, and it is the difference between a viable unit economic
and a paid-API bill that scales linearly with users.

## The principle

> Scout results cache **per H3 tile**, shared across all users.
> Personalization happens **only at ranking time**, per user, against the shared tile cache.

Two consequences that must be respected everywhere:

1. **A cache key for scout results never contains a user id.** If it does, you have destroyed the
   sharing and every user pays for their own Google call. This is the mistake to watch for.
2. **A ranked, personalized result is never written back into a shared cache.** Rank into a
   per-user structure or not at all.

## Resolution (DECIDED 2026-07-11)

- **Canonical tile: H3 resolution 8** (avg hex ≈ 0.74 km², edge ≈ 460 m) — the scout/cache unit and
  the coarsening target for privacy retention (PRD §16). City-block-scale sharing without rural
  tiles going empty.
- **Session coverage:** the tiles scouted for a session = the res-8 k-ring covering the session's
  reachable radius (from `time_budget_minutes` × mode speed — PRD §10 Stage A constants).
- **Uniqueness neighborhood** (`pct_tile`, SCORING §3): **k-ring 1 at res 8** (7 hexes ≈ 5.2 km²).
  Sparse fallback: if the neighborhood holds < 30 places, expand k-ring stepwise (max k = 3) before
  computing percentiles — rural Burgundy must not produce percentile ranks over 4 places.
- **Blocking for entity resolution** uses res 9 k-ring 1 (~350 m) — see ENTITY-RESOLUTION.md §3.
- Constants live in `config/tiles.php`, versioned like everything else. Store the index as string
  (`h3_index`, [03](03-migrations-and-schema.md)).

## Coverage: mode-aware and anisotropic (DECIDED 2026-07-11)

**Resolution is fixed; travel mode changes coverage.** Per-mode tile sizes would fragment the shared
cache into mode silos (a driver's scouting would never warm a walker's tiles) and make "unusual
*here*" depend on the visitor's vehicle. H3's hierarchy makes variable size unnecessary anyway: a
res-6 cell is exactly 49 res-8 descendants — coarse coverage is *more fine tiles*, never bigger ones.

What mode does change (constants in `config/tiles.php`):

| Mode | Effective speed | 3 h session reach | Shape | Scouts at far range |
|---|---|---|---|---|
| `walk` | 4.5 km/h × 1.30 | ~3–4 km (k≈4–5) | disc; mild cone if heading set | all (area is small) |
| `bike` | 14 km/h × 1.30 | ~10–12 km | cone when heading/destination set | food/practical near-only; rest full |
| `drive` | ~40 km/h × 1.35 | 40+ km | **cone/corridor required** — never a disc | far ring: Curated, History, Nature, Unusualness only |

- **Shape:** no direction → disc. `heading` → pear/cone: full reach within ±60° ahead, ~40% reach
  behind. `destination_point` → corridor along the origin→destination line. (PRD §9.2; Phase 2 adds
  continuous re-aiming.)
- **Payoff gradient:** "scout farther when driving" never means "scout *everything* farther." Each
  source declares near/far ranges per mode in its `SourceDescriptor` ([09](09-source-adapters.md)) —
  a café 30 km ahead is noise; a castle is not.
- **Fetch unit ≠ cache unit.** A driving corridor is thousands of res-8 tiles; never issue per-tile
  API calls for it. Run **one corridor-polygon query per source**, then bucket results into res-8
  tiles on write. The tile is the cache and accounting unit, not the fetch unit.
- **Hierarchical prefilter:** keep per-res-6 aggregate place counts (from our own `places` table,
  free) and descend only into res-6 cells that contain candidates — empty countryside costs nothing.

## Redis

Redis is **shared infrastructure on staging** (`docs/SERVER-DEPLOYMENT.md`), so all keys are
prefixed `travel_` (`REDIS_PREFIX`, `CACHE_PREFIX` — already set in `.env.example`). Never bypass
the configured prefix with a raw connection.

## Key structure

Structured, colon-delimited, versioned:

```
scout:{source_key}:{h3_index}:{adapter_version}          → normalized candidates for a tile
place:hours:{place_id}                                    → edge-verified opening hours (short TTL)
tile:state:{h3_index}                                     → tile_cache_state bookkeeping (PRD §14.2)
llm:{prompt_version}:{bundle_id}                          → generated text
```

- **The version belongs in the key.** Bumping `source_adapter_version` or `prompt_version` must
  invalidate the cache for free ([09](09-source-adapters.md), [10](10-llm-usage.md)). A cache that
  survives a version bump will serve stale output and make the version numbers a lie.
- **No user id in a scout key.** (Saying it twice on purpose.)
- Build keys in one place — a `CacheKeys` helper in `Domain/Places/Services/` — not by string
  concatenation at 20 call sites. A typo'd key is a silent 100% miss rate, which looks exactly like
  "the cache doesn't help much".

## TTL by data class

From PRD §9.3. The TTL is a property of the **source**, declared in its `SourceDescriptor`
(`ttl()`, [09](09-source-adapters.md)) — not a magic number at the call site.

| Data class | TTL |
|---|---|
| Static places | weeks–months |
| Opening hours | daily, **and verified before recommendation** |
| Events | hourly–daily, by source |
| Weather | frequent |
| News / local alerts | frequent, during active trips in the tile |
| LLM summaries | until the underlying evidence changes |

"Verified before recommendation" is a real rule, not a caveat: we do not tell a user a place is open
based on a day-old cache. Verify at the edge, serve from the edge cache
([09](09-source-adapters.md)).

## The Google edge cache

The **only** place Google-derived data may live is a short-TTL edge cache, and it never graduates
into the world model — not into `places_core`, not into `opportunities`, not "denormalized for
performance" ([03](03-migrations-and-schema.md), [09](09-source-adapters.md)).

Treat any PR that adds a Google field to a database table as a licensing incident, not a code-review
nit.

## Cache stampedes

A cold popular tile will be requested by many users at once. Two mechanisms, use both:

- `Cache::lock()` / `Cache::remember` with a lock around the expensive fill.
- `ShouldBeUnique` on the scout job, keyed on `(tile, source)` ([08](08-jobs-and-queues.md)).

Without these, the first bus of tourists arriving in a town triggers N identical paid API calls.

## Invalidation

- Prefer **short, honest TTLs** over clever invalidation. A wrong opening-hours cache is worse than
  a slightly slower response.
- Version-in-key handles model/prompt/adapter changes.
- `tile_cache_state` (PRD §14.2) records when a tile was last scouted per source, so the system can
  answer "is this tile cold?" without probing every key.
- Never cache a paginated *list*; cache the expensive underlying computation. Cached page 3 of a
  filtered list is a cache with a hit rate near zero and an invalidation problem near infinite.

## Cost visibility

Cache hit rate per source is a **product metric**, not an ops metric. It is the number that tells us
whether the shared-tile principle is actually working. Instrument it from the first scout.

## Checklist

- [ ] Scout cache key: source, tile, adapter version. **No user id.**
- [ ] TTL comes from the `SourceDescriptor`, not a literal.
- [ ] Version in the key, so a version bump invalidates.
- [ ] Lock or unique job around the expensive fill.
- [ ] Google data lives only in the short-TTL edge cache, and never anywhere else.
- [ ] Hit rate is instrumented.
