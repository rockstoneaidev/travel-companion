# 09 — Source adapters

A source adapter is how external data enters this system. It is also where the licensing constraints
stop being a document and start being code. Read `docs/DATA-SOURCES.md` and `docs/ODBL-REVIEW.md`
before adding one.

## The contract

Fixed by PRD §9.1. Every source implements it, without exception — that is what makes sources
pluggable without touching the recommendation engine:

```php
namespace App\Domain\Sources\Contracts;

interface ScoutSource
{
    public function supports(ScoutRequest $request): bool;
    public function search(ScoutRequest $request): array;
    public function normalize(array $raw): array;    // → shared candidate format
    public function ttl(): DateInterval;
}
```

Adapters live at `app/Domain/Sources/Adapters/{SourceName}Adapter.php`.

- `supports()` — cheap, pure, no I/O. "Do I have anything to say about this request?" A food scout
  in a request for toilets says no.
- `search()` — the only method that talks to the outside world. Returns raw source payloads.
- `normalize()` — raw → the shared candidate format. **Pure and testable**: given a fixture of raw
  source JSON, it returns candidates. No I/O here, so every adapter's normalization is unit-tested
  against recorded fixtures.
- `ttl()` — per PRD §9.3's TTL-by-data-class table. Static places: weeks. Opening hours: daily.
  Events: hourly-to-daily. News: frequent.

## License metadata is not optional

Every adapter registers itself in `SourceRegistry` (`app/Domain/Sources/Services/SourceRegistry`)
with metadata that the rest of the system **reads at runtime to decide what it is allowed to do**:

```php
new SourceDescriptor(
    key:                 'osm_overpass',
    license:             SourceLicense::Odbl,
    storage:             StoragePolicy::Persistable,   // may land in places_core
    attribution:         '© OpenStreetMap contributors, ODbL',
    ttl:                 new DateInterval('P30D'),
    adapterVersion:      'v1',
    rateLimit:           new RateLimit(perMinute: 60),
    credibilityTier:     CredibilityTier::Open,        // DATA-SOURCES §1.2
);
```

The `StoragePolicy` enum is the mechanism that makes the ODbL boundary enforceable in code rather
than in reviewer memory:

| `StoragePolicy` | Meaning | Examples |
|---|---|---|
| `Persistable` | May be written into `places_core`. ODbL-compatible open data only. | OSM, Overture, Wikidata, DATAtourisme, Base Mérimée |
| `EvidenceOnly` | May be stored in the evidence store with per-row license metadata. Never merged into the core. | Wikipedia/Wikivoyage (CC BY-SA) excerpts |
| `EdgeOnly` | **Never persisted anywhere.** Fetched at recommendation time, used, discarded. | **Google Places, Google Routes** |

The persistence layer checks the descriptor. A write of an `EdgeOnly` candidate into a world-model
table **throws** — it does not warn, and it is not a code-review convention that a tired reviewer
might miss. A CI check asserts that no edge-sourced value reached `places_core`
(ODBL-REVIEW §6 rule 1).

## Google, specifically

This is the constraint most likely to be broken by accident, so it gets its own section.

**Google Places and Google Routes data is edge-only.** Fetch at enrichment or recommendation time,
use it in the response, and let it go. What may be stored: **the Google `place_id` string**, in
`place_source_ids`, as an external identifier. Nothing else. Not the name, not the coordinates, not
the rating, not the review count, not the photo, not the opening hours — even though all of those
are exactly what you will be tempted to cache "just for a day".

Verified opening hours live in the **edge cache** (Redis, short TTL,
[12-caching-and-tiles.md](12-caching-and-tiles.md)), never in `places_core`. Caching a response
inside its permitted window is fine; writing it into the world model is not.

This is simultaneously a Google ToS requirement and an ODbL requirement (mixing proprietary data
into an ODbL Derivative Database poisons it). There is no version of this task where persisting it
is the right call.

## Language

Scouts query **in the local language of the region** (PRD §9.4). The best evidence for rural France
is in French; an adapter that only queries English is not doing its job. The LLM layer translates
and summarizes at generation time ([10](10-llm-usage.md)) — translation is a presentation concern,
not a storage one. Store the evidence in its original language, with its language tag.

## Versioning

`source_adapter_version` is recorded on **every** `source_items` row (PRD §15.1). When you change
what `normalize()` produces, you bump the version. Without it, a trace from three weeks ago cannot
be interpreted, and the replayer cannot tell a ranking regression from a normalization change.

## Failure & circuit breaking

- Every adapter has a **timeout**. An unresponsive tourism-board site must not hold a scout job open.
- Per-source **rate limits and circuit breakers** live in `SourceRegistry` (PRD §11). The adapter
  asks the registry for permission; it does not implement its own limiter.
- A source that fails is a **degraded** result, not a failed pipeline. Scouting a tile with 4 of 5
  sources produces recommendations from 4 sources and records that the fifth was unavailable —
  coverage honesty (PRD §15.3). Never fabricate coverage, and never fail the whole tile because one
  RSS feed 500'd.

## Adding a source — the checklist

- [ ] Read its terms. Actually read them. Record the license in `DATA-SOURCES.md`.
- [ ] Classify its `StoragePolicy`. If in doubt, `EdgeOnly` — that is the safe default.
- [ ] Register the `SourceDescriptor`: license, attribution string, TTL, rate limit, credibility
      tier, adapter version.
- [ ] Implement `ScoutSource`. `normalize()` is pure and has fixture-based unit tests.
- [ ] Attribution string flows through to the API resource ([06](06-resources-and-serialization.md))
      and the in-app attribution screen (ODBL-REVIEW §6 rule 6).
- [ ] Queries in the region's local language.
- [ ] Timeout, rate limit, circuit breaker.
- [ ] Instrument it: acceptance rate per scout is a headline metric (PRD §9.4).
