# 03 — Migrations & schema

The schema is where this product's hardest constraint lives. ODBL-REVIEW §6 says the licensing
boundary is "enforced in schema and code review, not in a wiki". This document is that enforcement,
written down.

## The licensing boundary — read this before your first migration

The database is split into three zones. **Which zone a table is in determines what may be written
to it.** This is a legal boundary, not an aesthetic one.

| Zone | Tables | May contain |
|------|--------|-------------|
| **Geo-core** (ODbL, publishable) | `places_core`, `place_source_ids` | **Only** ODbL-compatible open data: OSM, Overture, Wikidata, government open data. Names, geometry, categories, external-ID concordance. |
| **Evidence store** (per-row license) | `source_items`, `evidence_*` | Excerpts from any source, each row carrying its own license metadata and attribution. Never merged into the geo-core. |
| **Proprietary shell** | `opportunities`, `recommendations`, `curated_items`, `packs`, `profiles`, `profile_signals`, `scores`, `feedback`, embeddings, traces | Everything we create or learn. Keyed by `place_id`. |

Three rules follow, and they are absolute:

1. **A proprietary column never lands in a geo-core table.** No `curation_score` on `places_core`.
   If you want to attach something to a place, you create a new table keyed by `place_id`.
2. **Google Places data is never persisted, in any zone.** Store the Google `place_id` string as an
   external identifier in `place_source_ids` if you must join later — nothing else. No name, no
   coordinates, no rating, no opening hours, no photo. Opening hours verified via Google at
   recommendation time are served from the edge cache and are never written to `places_core`.
   (ODBL-REVIEW §6 rule 3, and Google's ToS.)
3. **Every geo-core attribute carries its source.** A `source` column (backed by the
   `App\Enums\SourceLicense` / source enum) on every row that lands in the core, so the CI check
   that no edge-sourced value reached the core can actually run.

If a migration seems to require breaking one of these, stop and raise it. This is one of the
"non-negotiable constraints" in CLAUDE.md.

## Naming

- Tables: plural `snake_case` — `trips`, `context_events`, `place_source_ids`.
- Pivots: singular, alphabetical — `place_tag`, not `tags_places`.
- Columns: `snake_case`. Foreign keys: `{singular}_id` — `trip_id`, `place_id`.
- Booleans read as assertions: `is_active`, `has_geometry`, `was_accepted`. Not `active`, not `flag`.
- Timestamps end in `_at`: `expires_at`, `verified_at`, `accepted_at`. Dates end in `_on`.
- Durations/measures carry their unit: `ttl_seconds`, `distance_meters`, `duration_minutes`.
  Never a bare `distance` — the unit ambiguity in PRD §15's sub-scores is a real cost.

## Keys

- **`uuid` primary keys** for anything that is exposed to a client, published, or crosses a system
  boundary: `places_core`, `trips`, `opportunities`, `recommendations`. A published ODbL dump with
  guessable sequential IDs also leaks our row counts.
- **`bigIncrements`** for high-volume append-only internals nobody links to: `context_events`,
  log-shaped tables.
- Use `$table->uuid('id')->primary()` and the `HasUuids` trait. UUIDv7 (`HasVersion7Uuids`) where
  insert order matters for index locality — `context_events`, `opportunities`.

## Enums

`varchar`, never `$table->enum()`. Full reasoning in [02-enums.md](02-enums.md).

```php
$table->string('status', 32)->default(TripStatus::Draft->value)->index();
```

## Postgres-specific columns

We run PostgreSQL 18 + PostGIS + pgvector (`deployment/docker/postgres/`). These are not portable
to SQLite, which is why the test suite runs on Postgres ([11-testing.md](11-testing.md)).

```php
// Geometry — geography(Point, 4326), so distance math is in meters on the sphere.
$table->geography('location', subtype: 'point', srid: 4326);
$table->spatialIndex('location');            // GIST

// Embeddings — pgvector. Dimension is fixed at migration time; changing it is a rewrite.
DB::statement('ALTER TABLE places ADD COLUMN embedding vector(1536)');
DB::statement('CREATE INDEX ON places USING hnsw (embedding vector_cosine_ops)');

// H3 tile — the shared cache key (PRD §9.3). Store as text, index it, it is queried constantly.
$table->string('h3_index', 20)->index();
```

Raw `DB::statement()` is acceptable and expected for pgvector and index types Laravel's builder does
not model. Keep it inside the migration; never scatter raw DDL through domain code.

## Required columns

**Version columns (PRD §15.1).** Any row that is the output of a model, a prompt, a policy or an
adapter records which version produced it. This is not optional and it is not "add it later" — a
trace without it is worthless.

```php
$table->string('scoring_model_version', 32);        // scores, recommendations
$table->string('prompt_version', 32);               // any LLM-generated text
$table->string('source_adapter_version', 32);       // source_items
$table->string('notification_policy_version', 32);  // deliveries (Phase 2)
$table->string('profile_model_version', 32);        // profiles
```

**TTL columns.** `opportunities` are ephemeral by design and must be reapable:

```php
$table->timestampTz('expires_at')->index();
```

`places` are canonical and permanent; `opportunities` are ephemeral and TTL'd. Never blur the two
(CLAUDE.md). A pruning job reads `expires_at`; nothing else decides expiry. The pruning job exists
(`ReapExpiredOpportunitiesJob`, nightly) and it **archives before it deletes**: expired time-bound
opportunities move their license-storable subset into `archived_opportunities` first
(VISION.md §2). Never write a plain `DELETE` against expired opportunities.

**Timezones.** Use `timestampTz` throughout. This is a travel product — a naive `timestamp` will be
wrong for a user in a different timezone from the server, and the bug will be subtle and late.

## Foreign keys

- `constrained()` with an explicit `onDelete`. Decide the delete behaviour deliberately: trip-level
  deletion is a privacy requirement (PRD §16), so trip children `cascadeOnDelete`.
- **Do not put a foreign key from the proprietary shell into the geo-core if it would prevent
  publishing the core independently.** A `place_id` reference is fine (the core doesn't know about
  it); a FK *from* `places_core` *to* a proprietary table is not.

## Indexes

Add the index in the same migration as the column, not in a follow-up "perf" PR.

- Every FK you filter or join on.
- Every column in a `WHERE`, `ORDER BY`, or cursor-pagination sort key.
- `spatialIndex` (GIST) on every geography column.
- `hnsw` on every vector column.
- Composite indexes in the order the query filters: `(trip_id, expires_at)`, not two singles.
- Partial indexes for the common hot filter: `WHERE expires_at > now()` on opportunities.

## Migration hygiene

- One concern per migration file. Descriptive names: `create_places_core_table`,
  `add_h3_index_to_opportunities_table`.
- **Migrations are append-only once merged.** Never edit a migration that has run on staging —
  write a new one.
- `down()` must actually reverse `up()`, including raw `DB::statement` DDL.
- No data manipulation in schema migrations. Backfills are one-off, idempotent artisan commands,
  runnable and re-runnable independently of deploy.
- Local dev auto-migrates on container start (see recent commits) — a broken migration breaks
  everyone's boot, not just yours.

## Checklist

- [ ] Which zone is this table in? Does every column belong to that zone?
- [ ] No Google-derived value is being persisted. At all.
- [ ] `varchar` for the enum, not `$table->enum()`.
- [ ] `timestampTz`, not `timestamp`.
- [ ] Units in the column names.
- [ ] The right `*_version` columns exist.
- [ ] `expires_at` if it's ephemeral; index it.
- [ ] Indexes added in this migration, not deferred.
