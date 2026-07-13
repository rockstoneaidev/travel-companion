# Travel Companion AI — Cost Model & Ledger

| | |
|---|---|
| **Document status** | Design v1.0 — DRAFT, not yet implemented |
| **Date** | 2026-07-13 |
| **Companion to** | [PRD.md](PRD.md) §14.3, §15 · [ADMIN.md](ADMIN.md) §2.4, §7.1 · [conventions/08](conventions/08-jobs-and-queues.md), [/10](conventions/10-llm-usage.md), [/12](conventions/12-caching-and-tiles.md) · [legal/ROPA.md](legal/ROPA.md) · [EPICS.md](EPICS.md) epic #10 |

---

## 1. Why this document exists

Two different questions hide inside "what does a user cost":

- **Forecast** — *what will a user cost next month?* This is the question pricing, the free tier,
  and the paid tier depend on. It is answered by a **model**: published vendor prices × assumed
  usage (§4). It can be answered today, before a single event is logged.
- **Measurement** — *what did we actually spend, and on whose behalf?* This is answered by a
  **ledger**: an append-only record of real spend with causal attribution (§5). It cannot be
  reconstructed retroactively — events not written are gone.

The model sets the prices; the ledger calibrates the model. Neither substitutes for the other, and
the sequencing asymmetry decides what to build first: **instrumentation is cheap now and impossible
later; allocation/reporting is expensive now and easy later.** So the ledger and the meter fixes
(§9) come before the France pilot; the dashboard (ADMIN §7.1) and allocation machinery (§7) come
when there is a bill worth looking at.

---

## 2. Principles

### 2.1 The four kinds of cost are not one number

Do not force them into one `cost_per_user` table; they behave differently.

| Kind | Nature | Treatment |
|---|---|---|
| **LLM + external API** | Metered, per-event, externally priced, causally attributable | A **ledger** (§5). Real money per row. |
| **CPU / memory** | Rented in advance in fixed lumps; no marginal price exists | A **usage meter**: record units (`cpu_ms`), never money. An allocation *rate* (monthly infra bill ÷ total measured units) is applied at report time only. Per-request peak memory is recorded for capacity work but never priced — memory is a peak, not a consumable. |
| **Storage** | A stock, not a flow — what we *keep* × how long, unrelated to what users retrieve | A periodic **size report** per table group. Note: `EnforceRetentionJob` is already the storage-cost control; retention policy and storage cost are the same lever. Not priced in v1. |
| **Platform / capex** | Region ingest and pack drafting (`IngestRegionBoxJob`, `BuildRegionWorldModelJob`, `DraftRegionPackJob`) spend real Gemini money on nobody's behalf | Ledger rows with `actor_kind = system` and a `region_id`; amortized per region over that region's users at report time. **Never attributed to the admin who clicked the button.** |

### 2.2 Record causal truth once; derive everything else

Shared caching (geo-tiles per conventions/12, and the 30-day LLM output cache in
`AgentOrchestrator`, keyed by evidence bundle — both deliberately user-independent) means
**marginal cost ≠ average cost**. Three attribution answers exist and answer different questions:

- **Causal** (whoever lit the fuse pays): what does one more request cost? Right for abuse
  detection and capacity. Wrong as a per-user bill — first-user-into-a-region looks like a whale.
- **Amortized** (split a cached artifact's cost over everyone who consumed it during its TTL):
  what is a user worth? Right for unit economics. Not knowable at write time — the denominator
  arrives later. Computed by the rollup (§7), never stored on the event.
- **Counterfactual** (`would_have_billed`): what a cache hit *would* have cost. The sum of
  `would_have_billed − billed` is the "is shared caching working?" number conventions/12 calls a
  product metric.

The ledger records causal truth plus the counterfactual; the amortized view is derived nightly.
One column never means two things.

### 2.3 Every row names its actor kind

`actor_kind ∈ {user, admin_emulated, system, warmer}`. ADMIN §2.4 already requires emulated-context
costs to be flagged and excluded from the trip-hour metric; this generalizes that rule to all
spend.

### 2.4 Money discipline

Integer **USD micros** (both Google Cloud and Gemini bill in USD), never floats. Every row stamps
`price_version` referencing a dated entry in `config/pricing.php` (§6), so history never silently
re-prices. EUR conversion happens at report time with a dated rate.

### 2.5 Caps come before attribution

A per-user attribution system tells you *who* cost €4,000 from a looping client — after the fact.
A **global daily spend cap** (§8) means it never happens. With real users in the field Jul 27–Aug 7
and a solo operator, the kill-switch is worth more than the dashboard. It ships first.

---

## 3. What actually costs money (Phase 1 inventory)

| Spend | Where | Vendor price (see §6 for versioned source) | Actor |
|---|---|---|---|
| Opportunity voice | `AgentOrchestrator::opportunitySummary`, cheap tier (`gemini-3.1-flash-lite`), via `GenerateOpportunityVoiceJob`; output cached 30 d keyed `llm:{prompt_version}:{bundle_id}` — shared across users | ~$0.25 / M input, ~$1.50 / M output | user (causal) |
| Curated-claim drafting | `AgentOrchestrator::curatedClaim`, capable tier (`gemini-3.5-flash`), pack pipeline | ~$1.50 / M input, ~$9.00 / M output | system (region capex) |
| Edge routing (planned) | Google Routes API, served items only behind the estimator gate (PRD §10, decisions log 2026-07-11) | $5 / 1,000 (Essentials) | user |
| Edge place enrichment (planned) | Google Places, edge-only per non-negotiable #2 | $5–17 / 1,000 depending on SKU | user |
| Everything else | All five source adapters (OSM, Overture, Wikidata, Datatourisme, Mérimée), Open-Meteo, OSM raster tiles | **free / open** | — |
| Infra | Hetzner staging, fixed monthly | fixed lump — §2.1 usage-meter treatment | — |

Two consequences worth stating plainly:

1. **Today's only real money is Gemini.** During the pilot, Google's free monthly tier
   (10,000 Essentials events) likely covers all edge calls for a handful of users. Expected total
   pilot spend is single-digit euros.
2. **When edge routing lands, it dominates.** One Routes call is $0.005 — roughly **8× the cost of
   a whole uncached voice generation**. Five served items × one Routes call each = $0.025 per feed
   refresh, vs ~$0.003 of LLM for the same feed fully cold. The estimator gate (already decided)
   is the load-bearing cost control, and Routes responses should be cached per
   (origin-tile, place, mode) with the same shared-tile logic — that cache is a unit-economics
   feature, not an optimization.

---

## 4. Unit-economics model v1 (the forecast)

Parameters are declared guesses, to be calibrated from the ledger after the first pilot week.
Prices from §6 (2026-07 sheet).

| Parameter | v1 guess | Calibrate from |
|---|---|---|
| Feed loads per active trip-day `F` | 10 | `explore_sessions` |
| Items served per load `N` | 5 | constant (SCORING feed selection) |
| Voice cache miss rate `m` | 0.3 steady-state, 1.0 in a cold region | ledger `cached` ratio |
| Tokens per voice generation | 1,500 in / 150 out | `cost_events` token columns |
| Routes calls per load (post-launch) | ≤ N, after estimator gate + route cache | ledger |

**Per uncached voice generation** (flash-lite):
`1,500 × $0.25/M + 150 × $1.50/M ≈ $0.0006`.

**LLM cost per active trip-day**: `F × N × m × $0.0006` ≈ 10 × 5 × 0.3 × 0.0006 ≈ **$0.009** —
about one cent, falling with user density (shared cache), rising toward ~$0.03 for the lone
first user in an uncached corridor.

**Routes cost per active trip-day** (worst case, no route cache): `F × N × $0.005` = **$0.25**.
With a route cache at 70% hit rate: ~$0.075. This is the number that decides the free tier, not
the LLM.

**Region pack capex** (3.5-flash): ~3,000 in / 400 out per item ≈ $0.008/item; a 300-item pack
≈ **$2.50 one-time per region** — negligible, amortize and forget.

**What this means for pricing.** With the route cache built, a free user's marginal cost is on
the order of **$0.01–0.08 per active trip-day**, and users travel maybe 20–40 days/year — so a
free tier is cheap *per user* and the real exposure is (a) the fixed infra floor, (b) abuse/looping
clients (§8), and (c) Routes without a cache. A paid tier priced at even €2–3/month per active
traveler clears marginal cost by ~10×. The model's job from here is to watch whether the ledger
agrees.

---

## 5. The ledger: `cost_events`

Append-only, no updates ever, partitioned monthly on `occurred_at`.

| Column | Notes |
|---|---|
| `occurred_at` | when the spend happened, not when the row landed |
| `actor_kind` | enum §2.3 |
| `user_id` | nullable; **no FK cascade** — erasure nullifies (§10) |
| `trip_id`, `session_id`, `recommendation_id`, `opportunity_id`, `region_id` | nullable correlation ids; cost accretes to these **after the fact**, because spend is asynchronous and multi-process (§9.2) |
| `category` | enum: `llm`, `api`, `compute` |
| `vendor`, `resource` | e.g. `gemini` / `gemini-3.1-flash-lite`; `google_maps` / `routes_essentials` |
| `host` | host only — **never a URI**; query strings carry coordinates (ROPA §7.2 / finding B1) |
| `input_tokens`, `output_tokens`, `cached_input_tokens` | LLM rows; the split is mandatory — the three quantities have three prices |
| `calls`, `cpu_ms`, `peak_mem_kb` | unit columns for api/compute rows |
| `billed_usd_micros` | integer micros; 0 for cache hits and compute rows |
| `would_have_billed_usd_micros` | the counterfactual on cache-hit rows (§2.2) |
| `cached` | boolean |
| `price_version`, `prompt_version` | audit trail (non-negotiable #7 spirit) |
| `h3_cell` | res-8, for regional cost maps; nulled with `user_id` at de-identification (§10) |

**Write discipline (conventions/08):** never insert on the hot path per call. The in-process meter
accumulates; a single batched multi-row insert flushes at the end of the unit of work — a
terminating callback for requests, a `MetersCost` job middleware for queue jobs. One statement,
N rows. The job middleware doubles as the currently missing job base-class seam.

---

## 6. Pricing config

`config/pricing.php`, not a database table — dated entries reviewed in PRs:

```php
'2026-07' => [
    'llm' => [
        'gemini-3.1-flash-lite' => ['in' => 250_000, 'out' => 1_500_000, 'cached_in' => /* verify */],
        'gemini-3.5-flash'      => ['in' => 1_500_000, 'out' => 9_000_000, 'cached_in' => /* verify */],
    ], // USD micros per 1M tokens
    'api' => [
        'routes_essentials'      => 5_000,   // USD micros per call
        'place_details_essentials' => 5_000,
        'place_details_pro'      => 17_000,
    ],
],
```

> ⚠️ The numbers above were taken from public price pages on 2026-07-13 and **must be verified
> against the Google billing console before the first `price_version` is frozen**. Cached-input
> pricing for both Gemini models is unverified.

Every ledger row stamps the sheet key as `price_version`. A price change is a new dated entry,
never an edit.

### 6.1 Price-drift check (automated verification, deliberate repricing)

Machine-readable price feeds exist, but they must never price the ledger directly — a ledger
repriced by a drifting third-party feed is not an audit trail, and the aggregators lag and
occasionally err on exactly the rates that matter here (cached-input, batch). Their correct use
is a **drift check**: a scheduled command (weekly, `schedule:` + Slack/log alert) that fetches
current prices for our handful of SKUs, compares them to the active `config/pricing.php` sheet,
and flags any mismatch for a human to review and land as a new dated entry.

| Feed | Covers | Notes |
|---|---|---|
| LiteLLM `model_prices_and_context_window.json` (GitHub, BerriAI/litellm) | Hundreds of LLMs incl. Gemini; input/output/cached rates | De-facto community standard; free, no key; community-maintained → verify before freezing a sheet |
| `models.dev/api.json` | Open-source model DB with per-model cost | Same caveat |
| OpenRouter `GET /api/v1/models` | Live per-model pricing | Prices are OpenRouter's (usually provider list-price passthrough) |
| **Cloud Billing Pricing API** (`cloudbilling.googleapis.com`) | **Authoritative** for everything billed through Google Cloud: Maps Platform SKUs (Routes, Place Details), and Gemini API on a Cloud-billed key | Official but clunky: per-SKU IDs, tiered pricing formulas, needs an API key. This is the one that can actually *close* the §12.1 verification loop |

---

## 7. Derived views (deferred until there is a bill)

A nightly rollup job → `cost_daily` (per user_id × category × vendor × day), which is where:

- **amortization** spreads a cached artifact's cost over its consumers,
- **region capex** spreads `actor_kind = system` spend over the region's active users,
- the **infra allocation rate** (monthly bill ÷ total `cpu_ms`) is applied,
- `admin_emulated` rows are excluded from product metrics (ADMIN §2.4).

The `/admin/costs` dashboard (ADMIN §7.1, `costs_view` permission — which does not exist yet)
reads these views. Its priority list stands: cost per active trip-hour first (PRD §14.3, open
question 4), then vendor/tier breakdown, cost-per-recommendation distribution
(a €0.40 recommendation is a bug — conventions/10), cache savings (§2.2 counterfactual), breaker
states.

---

## 8. Caps & kill-switch (ships before the pilot)

Deterministic policy, in the spirit of non-negotiable #4 — no model decides about money:

- **Global daily budget**: a Redis counter (prefixed `travel_`) incremented on every paid-call
  flush; when the day's `billed_usd_micros` crosses `COST_DAILY_CAP_USD`, paid calls short-circuit
  — Gemini generations fall back to the template (which the voice path already treats as always
  valid), edge calls fall back to the estimator. The product degrades to Phase-1-of-Phase-1; it
  never stops serving.
- **Per-user daily ceiling**: same mechanism keyed by user, catching a looping client without
  taking the fleet down.
- Both emit a log + Pulse-visible event when tripped. Cap values live in config, not code.

---

## 9. Known defects in the current skeleton (verified 2026-07-13)

The existing `CostMeter` / `recommendations.cost` skeleton is the right instinct ("measured zero,
not asserted zero") with three real bugs:

1. **Worker-lifetime accumulation.** `CostMeter` is bound `singleton()`
   (`AppServiceProvider.php:58`) and `reset()` has zero callers in `app/`. In a Horizon worker the
   meter accumulates across every job forever; each job's costs bleed into the next trace. Fix:
   `scoped()` (flushed per request/job), which is also what the ledger flush (§5) needs.
2. **The trace lies about LLM tokens — structurally.** `RankSession` writes the `cost` jsonb on
   the serve path; voice generation is dispatched (`GenerateOpportunityVoiceJob`), never awaited —
   correctly — so the tokens are spent later, in another process, and land in a meter nothing
   reads. `recommendations.cost.llm_tokens` is always zero by construction. This is the proof that
   cost must accrete to correlation ids after the fact (§5), not be written once at serve time.
   The serve-path blob stays, documented as *serve-path cost only*.
3. **The token split is destroyed at the moment of capture.** `GeminiClient.php:93` records
   `$inputTokens + $outputTokens`, discarding the split, the model, and `cachedContentTokenCount`
   (never read from `usageMetadata`; `GenerationResult::$cached` is never true for live calls) —
   one line after extracting exactly the fields needed to compute money. Gemini prices input,
   output, and cached input at three different rates. Fix the meter API:
   `recordLlm(model, in, out, cachedIn)`.

---

## 10. GDPR (do this in the same PR as the migration)

A per-user cost log is **personal data** — a timestamped behavioural record of when someone used
the app and how hard, arguably more revealing than the recommendations themselves. Per the ROPA
rule ("if you add a table with personal data, ROPA.md is wrong until you update it"):

- **Lawful basis**: legitimate interest (billing integrity, abuse prevention, capacity planning).
  Add to ROPA §tables and PRIVACY-NOTICE.
- **Retention**: `user_id`, correlation ids, and `h3_cell` are **nulled at 90 days** by
  `EnforceRetentionJob`; the de-identified rows are kept 24 months for accounting. Account
  deletion nullifies the same columns immediately — which is why there is **no FK cascade**:
  erasure detaches the person without blowing a hole in the P&L. `ExportAndErasureTest` must
  assert this (the B7 lesson: no `user_id` FK means schema enumeration won't find it for you).
- **Minimisation**: `host` + SKU only, never URIs (the B1 lesson — query strings carry precise
  coordinates).

---

## 11. Sequencing

**Now (belongs to epic #10, before Jul 27):**

1. The three §9 fixes (scoped meter + flush seam, ledger accretion by correlation id, token split).
2. `cost_events` migration + `MetersCost` job middleware + request flush — with the §10 ROPA/
   retention/erasure work in the same PR.
3. `config/pricing.php` with the verified 2026-07 sheet.
4. The §8 kill-switch.

**Later (when there is spend to look at):** nightly rollup, amortized/capex allocation, the
`/admin/costs` dashboard (`costs_view` permission), storage size report, infra allocation rate.

## 12. Open questions

1. Verify all §6 prices against the billing console; freeze `price_version: 2026-07`.
2. Route-cache design (origin tile × place × mode, TTL) — needed before edge routing lands; it is
   the single biggest lever in §4.
3. Cap values for §8 (proposal: $10/day global, $1/day/user for the pilot).
4. Whether `cost_daily` is EUR-reported at a fixed monthly rate or per-day ECB rate (proposal:
   monthly, dated in config).
