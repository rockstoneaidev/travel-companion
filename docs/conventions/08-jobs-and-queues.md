# 08 — Jobs & queues

The pipeline is event-driven and asynchronous: scout → enrich → rank → deliver. Jobs are the spine
of the product, and a job that runs twice, or silently doesn't run, is a data-integrity bug rather
than a performance one.

## Layout

Fixed by PRD §14.1 — jobs live outside `app/Domain/`, because they are a delivery mechanism, same as
a controller:

```
app/Jobs/
    Scouts/       NearbyPlaceScoutJob, EventScoutJob, HistoryScoutJob,
                  UnusualnessScoutJob, CuratedScoutJob, RouteScoutJob [P2]
    Enrichment/   EnrichOpportunityJob, VerifyOpeningHoursJob,
                  CalculateRouteFrictionJob, GenerateEmbeddingJob
    Ranking/      ScoreOpportunityJob, DecideRecommendationJob
    Delivery/     SendPushNotificationJob [P2], RegisterGeofencePayloadJob [P2]
```

**A job is a thin wrapper**, exactly like a controller ([01](01-domain-modules.md)):

```php
final class ScoreOpportunityJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $opportunityId) {}

    public function handle(OpportunityScorer $scorer): void
    {
        $scorer->score($this->opportunityId);
    }
}
```

The scoring logic is in `Domain/Recommendations/Services/OpportunityScorer`. The job knows how to be
retried; it does not know how to score.

## Constructor payloads

**Pass IDs, never models.** A serialized model in a payload is a snapshot that goes stale between
dispatch and execution, and `SerializesModels` will re-query anyway — badly, and it will throw
`ModelNotFoundException` if the row was deleted (which, for TTL'd opportunities, happens).

Pass a `string $opportunityId`. Re-fetch inside `handle()`. If the row is gone, that is a normal
outcome — return quietly, don't fail the job.

Payloads must be small and serializable: IDs, an H3 index, an enum, a small DTO. Never an evidence
bundle, never raw source JSON.

## Idempotency — the hard rule

**Every job must be safe to run twice.** Queues guarantee at-least-once delivery; a worker killed
between "work done" and "ack" will re-run the job. This is not an edge case, it is a Tuesday.

Concretely:
- Writes use `updateOrCreate` / `upsert` keyed on a natural key, not blind `create`.
- A job that has already produced its output detects that and returns.
- Side effects that cannot be undone (a push notification, a paid API call) are guarded — see below.

```php
public function handle(): void
{
    $opportunity = Opportunity::find($this->opportunityId);
    if ($opportunity === null || $opportunity->expires_at->isPast()) {
        return;                                        // not a failure; the world moved on
    }
    // ...
}
```

## Uniqueness & rate control

- `ShouldBeUnique` (with `uniqueId()` and `uniqueFor()`) on any job where two concurrent runs would
  duplicate work or spend money twice. **Every scout job is unique per (tile, source)** — two users
  entering Beaune at the same moment must not trigger two paid Google calls for the same tile.
  This is the job-level half of the shared-tile-cache principle (PRD §9.3,
  [12-caching-and-tiles.md](12-caching-and-tiles.md)).
- `WithoutOverlapping` where a job mutates shared state (e.g. rebuilding a tile's candidate set).
- `RateLimited` / `Redis::throttle` on anything hitting an external API with a quota. Per-source
  limits and circuit breakers live in `SourceRegistry` (PRD §11) — the job asks the registry, it
  does not hardcode a limit.

## Dispatch

- **`dispatch()->afterCommit()`** for any job dispatched from inside a transaction — or set
  `after_commit => true` on the queue connection and stop thinking about it. A job that starts
  before its transaction commits will not find its own row. This is the single most common
  async bug in Laravel codebases.
- Dispatch from Actions, not from controllers, and not from model events. A model event that fans
  out jobs makes the pipeline impossible to reason about or to replay.
- Chains (`Bus::chain`) for strictly sequential stages; batches (`Bus::batch`) for fan-out with a
  completion callback (scout N sources for a tile, then rank when all have landed). Prefer a batch
  over a chain when the steps are independent — a chain serialises work that could be parallel.

## Queues, retries, failure

### The lanes

Lanes are separated by the **shape of the work** — how long it runs, how badly it can wait, how bad
it is if it runs twice — not by the feature that queued it. They live in `App\Enums\QueueLane`, and
each has its own Horizon supervisor (`config/horizon.php`).

| Lane | Connection | Timeout | Processes | What it carries |
|---|---|---|---|---|
| `realtime` | `redis` | 30s | 2–3 | Push, broadcast. Someone is waiting. **Dormant until Phase 2** (PRD §8) — the lane exists so pushes never land behind a world-model build. |
| `default` | `redis` | 60s | 3–6 | Short work off the back of a request: feedback, taste updates, session close. |
| `voice` | `redis` | 90s | 3–4 | LLM generations. Retryable, and must never block a feed. |
| `scouts` | `redis` | 60s | 4–6 | Tile warming. Thousands of tiny DB jobs; wide and short. |
| `ingest` | **`redis-long`** | 900s | **1** | World-model builds. Minutes per job. **Serial on purpose.** |

```php
public function __construct(public readonly string $tileId)
{
    $this->onQueue(QueueLane::Scouts->value);
}
```

### `retry_after` > timeout. Always.

**This is the invariant, and breaking it is invisible in review and obvious in production.**

`retry_after` is how long the queue waits before deciding a reserved job has died and giving it to
somebody else. If a job's `timeout` exceeds its connection's `retry_after`, the queue hands a
**still-running** job to a second worker: it runs twice, `attempts` climbs past `tries`, and it fails
as `MaxAttemptsExceeded` **while doing nothing wrong**.

That is exactly how the Dijon world-model build died on staging (2026-07-14): a 420-second job on a
connection whose `retry_after` was 90 seconds. Attempts 2, Retries 0, and a stack trace that says
nothing about the actual cause.

`retry_after` is a property of the **connection**, not the queue — which is *why* `ingest` has its own
connection. Forcing a 30-minute `retry_after` onto the shared connection would mean a genuinely dead
push notification waits half an hour to be retried.

Enforced by `tests/Feature/QueueConfigTest.php`, which also asserts every lane has a supervisor
listening to it: a lane nobody consumes is a queue that fills up forever in silence.

### Keep jobs short enough that the queue never has to guess

`BuildRegionWorldModelJob` used to run **every source in one job** — Mérimée, DATAtourisme, Wikidata,
then OSM with its adaptive splits and politeness sleeps. For a real city that is many minutes, and a
job that long is a job the queue starts guessing about. It now chains **one source per job**, then
`resolve` in tile batches, then `photos`, then `warm`. Each hop is bounded; the chain is what is long.

`ingest` runs **one process**, and that is a product constraint rather than tidiness: public Overpass
returned 504s when the corridor cities ran back to back, and two region ingests at once is how you get
rate-limited off a source you do not pay for.

- `$tries` and `$backoff` are **explicit** on every job. Exponential backoff for external APIs:
  `public $backoff = [10, 60, 300];`
- `$timeout` explicit, and lower than the Horizon worker timeout. An LLM call needs a generous one;
  set it deliberately rather than inheriting a default that will truncate it.
- Distinguish **retryable** (network blip, 429, 5xx) from **terminal** (404, invalid payload,
  license violation). Terminal conditions call `$this->fail($e)` — do not burn 3 retries on a
  request that can never succeed.
- `failed(Throwable $e)` on any job whose failure needs to be visible in the trace. A silently
  failed enrichment produces a recommendation with a hole in it.
- Failed jobs go to the `failed_jobs` table and Horizon. A job class with no `failed()` and no
  alerting is a job whose failures nobody will ever see.

## The decision trace (PRD §15)

Anything in the ranking or delivery path **writes its trace as part of its work, in the same
transaction as its output**. Not "logged" — persisted, structured, queryable.

Every recommendation records: which scouts ran, which sources answered, every sub-score, the
`scoring_model_version`, the `prompt_version` of any generated text, which policy allowed delivery
(`notification_policy_version`), and what the user did next.

A trace written "later, if we have time" is a trace that does not exist, and the trip replayer
(PRD §15.2 — the highest-leverage dev tool in this project) cannot be built on top of missing data.
Treat the trace as an output of the job, equal in importance to the recommendation itself.

## Cost

Every external API call and every LLM call is logged with its cost against the recommendation that
caused it (PRD §11). A scout job that fans out to four paid sources and produces nothing must be
visible as exactly that.

## Phase discipline

`Delivery/` jobs, geofences, push and Reverb are **Phase 2** (CLAUDE.md constraint 5). Do not build
them, do not add "just the interface for now". Phase 1 is pull-based and foreground-only.

## Checklist

- [ ] Job is a thin wrapper; the logic is in a domain service.
- [ ] Payload is IDs, not models.
- [ ] Running it twice is safe.
- [ ] `ShouldBeUnique` if it costs money or duplicates work.
- [ ] `afterCommit` if dispatched inside a transaction.
- [ ] Explicit `$tries`, `$backoff`, `$timeout`, and a named queue.
- [ ] Terminal failures fail fast; retryable ones back off.
- [ ] It writes its trace and its cost.
