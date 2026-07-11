# Travel Companion AI — Entity Resolution (v1 algorithm spec)

| | |
|---|---|
| **Document status** | Design v1.0 (`resolver_version: v1`) |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §9.6, §10 step 6 · [TAXONOMY.md](TAXONOMY.md) §3 · [DATA-SOURCES.md](DATA-SOURCES.md) §1.2 · [ODBL-REVIEW.md](ODBL-REVIEW.md) §6 · [SCORING.md](SCORING.md) §4.6 |

---

## 1. Why this document exists

PRD §9.6 declares entity resolution a first-class subsystem — the same place appears in OSM,
Overture, Wikidata, Wikipedia, and tourism feeds with name variants, multilingual names, and
coordinate drift — but gives no algorithm. The canonical `places` table cannot be built without one.
This is the v1: deterministic, versioned, auditable, and cheap-first.

**Principles** (mirroring SCORING §9's discipline):

1. **Explicit IDs before fuzzy matching.** The open-data ecosystem already cross-references itself;
   use that first and fuzzy-match only the remainder.
2. **Deterministic and versioned.** Same inputs + same `resolver_version` → same output. All
   constants live in one versioned config (`config/resolver.php`); any change mints a new version.
3. **Every decision is recorded.** Match score, threshold band, and evidence per merge — the audit
   trail is what makes false merges debuggable and the gold set fittable.
4. **Merges are survivable, never destructive.** Source rows are kept; canonical ids are stable;
   un-merging is a supported operation.
5. **Canonical-core sources only.** Resolution runs over open-data sources exclusively. Google data
   never participates (conventions/09: edge-only) — it could taint `places_core` (ODBL-REVIEW §6).

---

## 2. Data model

```text
source_items         (already in PRD §14.2: raw normalized candidates, per source —
                      name(s), point, source tags, external ids, license, retrieved_at)
places               (canonical — PRD §14.2, TAXONOMY §6.1)
place_source_ids     (place_id ↔ {source, external_id}; one row per contributing source item)
place_match_decisions(source_item_id, place_id?, score, band, per-signal scores jsonb,
                      resolver_version, decided_by: auto|reviewer, created_at)
place_merges         (old_place_id → canonical_place_id, merged_at, resolver_version)
                      -- redirect table: user data / FKs referencing a merged-away place survive
```

`place_merges` is the un-merge/redirect mechanism: canonical ids are never deleted, only redirected.
Application reads resolve redirects at the `PlaceLookup` contract boundary (conventions/01).

---

## 3. The pipeline

Runs per H3 tile (conventions/12) at ingest time, and incrementally when source items change.
Taxonomy assignment (TAXONOMY §3) happens in the same pass.

### Stage 0 — Normalize

- Names: trim, collapse whitespace, lowercase for comparison **but preserve originals** (multilingual
  alternates kept as a list — never pick one "true" name at this stage).
- Diacritics: compare both raw and diacritic-folded forms (French place names make folding lossy;
  use it as a *second* comparison, not a replacement).
- Point: source geometry → representative point (centroid for polygons).

### Stage 1 — Explicit-ID joins (free, high-confidence)

Join on cross-references the sources already publish, in this order:

| Join | Mechanism |
|---|---|
| OSM ↔ Wikidata | OSM `wikidata=*` tag (very common on notable places) |
| OSM ↔ Wikipedia | OSM `wikipedia=*` tag → Wikidata via sitelink |
| Wikipedia ↔ Wikidata | sitelinks (canonical) |
| Overture ↔ OSM | Overture provenance/source refs where present (GERS bridge) |
| Gov/tourism feeds ↔ Wikidata/OSM | external refs where the feed provides them (DATAtourisme sometimes does) |

An explicit-ID match **auto-merges** regardless of fuzzy score (band `explicit`), with one sanity
guard: if the joined points are > 1 km apart, route to review instead (tag vandalism / stale refs
exist).

### Stage 2 — Blocking (make fuzzy matching cheap)

Only compare pairs that share a block:

```text
block = same H3 res-9 cell or its k-ring-1 neighbors     (~350 m neighborhood)
        AND (name trigram similarity ≥ 0.3               (pg_trgm GIN index)
             OR same PlaceTypeDomain)
```

Everything outside a block is `distinct` by construction. This keeps the pairwise work linear-ish
per tile and entirely inside Postgres.

### Stage 3 — Match score (pure function, recorded per pair)

```text
match = 0.45 × name_sim      max over (raw, folded) × all alternate-name pairs;
                             Jaro-Winkler primary, trigram as floor
      + 0.25 × proximity     1 − ramp(distance_m, 0, R_type)
                             R_type: dense urban POI 100 m · building-scale 150 m ·
                             park/nature feature 500 m (from PlaceTypeDomain)
      + 0.15 × type_compat   same PlaceType 1.0 · same domain 0.6 ·
                             known-compatible pair (church↔chapel, cafe↔bakery) 0.3 · else 0
      + 0.15 × embed_cos     cosine of pgvector embeddings (PRD §10 step 5) when both exist;
                             absent → drop term and renormalize (SCORING §2.5 discipline)
```

### Stage 4 — Threshold bands

```text
match ≥ 0.82        AUTO-MERGE   (band: high)
0.60 ≤ match < 0.82 REVIEW       (queue in the admin console — ADMIN.md; serveable as
                                  separate places meanwhile: a duplicate is annoying,
                                  a false merge is data corruption)
match < 0.60        DISTINCT
```

Asymmetry is deliberate: false merges are worse than duplicates, so the auto band is conservative
and the review band wide. Expect the review queue to be the main curation cost early; thresholds are
refit against the gold set (§6) per `resolver_version`.

**Chain guard:** identical names, > 250 m apart, in chain-prone types (`cafe`, `restaurant`,
`bakery`, branded `specialty_shop`) never auto-merge on name alone — franchises are distinct places.

### Stage 5 — Survivorship (field-level, by source credibility)

The canonical row is assembled per field, not per source:

| Field | Rule |
|---|---|
| geometry | most precise open geometry: OSM/Overture first; > 150 m disagreement → conflict |
| name + alternates | local-official (gov/tourism, Tier A) → OSM local name → others as alternates |
| `type` / `type_domain` | TAXONOMY §3 credibility order |
| `facets` | union of rule-based priors; LLM pass runs after merge (TAXONOMY §4.2) |
| `source_tags` | union — **never discarded** (TAXONOMY §3) |
| opening hours etc. | per-claim with per-source TTL (conventions/09); disagreement → conflict |

Conflicts are recorded and **lower the place's confidence** exactly as SCORING §4.6 specifies
(−0.15 per conflicting claim group). Cross-source agreement raises `corrob`.

---

## 4. Events are not entity-resolved

An event (`OpportunityKind: event`) links to its **venue** place via the same Stage 1–4 machinery
applied to the venue name+point, but events themselves are never merged into `places` — they are
short-lived opportunities (PRD §14.2). An unresolvable venue leaves the event grounded to a
coordinates-only stub flagged for review.

---

## 5. Operations

- **Idempotent re-runs:** nightly incremental over new/changed `source_items`; a full-tile re-run
  after a `resolver_version` bump is a batch reprocess (raw items retained — same principle as
  TAXONOMY §8).
- **Un-merge:** split the offending `place_source_ids` rows into a new place, add a
  `place_match_decisions` row with `decided_by: reviewer`, leave the redirect for anything that
  referenced the merged id.
- **Metrics** (admin console, ADMIN.md): auto-merge rate, review-queue depth and age, un-merge
  count (= false-merge rate proxy), duplicates-reported-by-users.

## 6. Gold set & fitting

Per region, hand-label ~200 candidate pairs (mix of true matches, near-misses, chain traps,
multilingual variants) — Stockholm first, then the France corridor cities. Every `resolver_version`
reports precision/recall against it before rollout; thresholds and signal weights are refit exactly
like scoring constants (SCORING §9: fitting mints a new version, never edits one).

## 7. Module placement

Per conventions/01: `EntityResolver` lives in `Domain/Places/Services/`; match/merge write
operations are `Domain/Places/Actions/` (`ResolveSourceItem`, `MergePlaces`, `SplitPlace`); the
review queue is read via `Domain/Places/Queries/` and surfaced in the admin console (ADMIN.md).
`MatchBand` is a Places-module enum (conventions/02).
