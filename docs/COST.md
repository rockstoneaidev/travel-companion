# Travel Companion AI — Cost Model & Ledger

| | |
|---|---|
| **Document status** | v1.2 — **implemented: E24 (ledger, caps, strip) + E25 (rollup, explorer, drift check)** |
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
| Edge place enrichment | Google Places (hours), edge-only per non-negotiable #2 — but **OSM `opening_hours` answers the easy cases first, for free** (E50 lever): the verify step reads the tag we already ingested and only pays Google for the grammar it cannot parse or the near-boundary times where the timezone matters. | $5 / 1,000 (only what OSM could not answer) | user |
| Edge routing | Google Routes (Stage B), edge-only — the dominant Google spend by call volume. **Free alternative built (E43): self-hosted OSRM**, flipped on with `ROUTING_DRIVER=osrm` once the ledger says so. | $5 / 1,000, or **$0 self-hosted** | user |
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

### 5.1 Designed-for future: per-user LLM chat (Phase 3 — noted 2026-07-13)

A per-user "ask questions about this place" chat is a *possible* Phase 3 feature. Nothing is
built for it now (phasing is strict — PRD §8, non-negotiable #5), but the ledger must not
preclude it, because it inverts today's LLM economics:

- Today every LLM call is user-independent and shared-cached; marginal per-user LLM cost is ~0.
  A chat is **causally user-attributed by nature** — no shared cache, every turn is real money on
  one user's row. Per-user LLM cost stops being a rounding error and likely becomes the dominant
  per-user cost, ahead of Routes.
- The ledger shape already fits (`category = llm`, `user_id`, `actor_kind = user`, one row per
  turn), but **reserve a nullable `conversation_id` correlation column in the v1 migration** —
  a spare column is cheap now, a backfill is not.
- Multi-turn chat re-sends context every turn, so **cached-input pricing** (still unverified in
  §6) becomes the load-bearing rate; per-turn `cached_input_tokens` is what separates a cheap
  conversation from an expensive one.
- The §8 **per-user daily ceiling becomes the binding control**, and at that point it must be
  user-visible near the limit ("chat is resting for today"), not a silent degradation.
- For pricing: chat is the natural **paid-tier feature** — the one surface where marginal cost
  scales linearly with engagement instead of being flattened by shared caches. The free tier can
  stay generous precisely because it excludes (or tightly rations) chat.
- Non-cost implications, flagged now for the Phase 3 spec, not solved here: chat content is user
  personal data (new tables → ROPA, retention, erasure; free-text questions can reveal Art. 9-
  adjacent interests — the DPIA §3.2 trap again, worse because it is verbatim). Grounding rules
  survive unchanged: the LLM is still never a source of facts (non-negotiable #3) — chat answers
  generate from evidence bundles; and conventions/10's "never call a model synchronously in a web
  request" needs a streaming answer for chat, not an exemption.

**Write discipline (conventions/08):** never insert on the hot path per call. The in-process meter
accumulates; a single batched multi-row insert flushes at the end of the unit of work. One
statement, N rows.

### 5.2 Two amendments the implementation forced (E24, 2026-07-13)

Both are cases where building the thing falsified the design, and both are now the code:

**a) The queue flush is a global listener, not a `MetersCost` job middleware.** A middleware is
opt-in, so the first job whose author has not read this document spends money invisibly — and it
*will* be a job, because everything expensive here (voice, pack drafting, ingest) is a job. The
flush therefore listens on `JobProcessed`/`JobFailed`, mirroring the global outbound-HTTP hook and
for the same stated reason: *coverage by default beats correctness by convention*. A job that
knows whose behalf it acts still says so (`GenerateOpportunityVoiceJob` calls `actingAs()` with the
user whose feed lit the fuse); a job that says nothing is booked as `system`, which is the honest
answer for ingest.

**b) A synchronous job is not a unit of work.** `dispatchSync` — and the sync connection the test
suite runs on — executes a job *inside* the enclosing request, on the same scoped meter. The queue
listeners must therefore ignore sync jobs entirely: otherwise they re-stamp the actor as `system`
mid-request and drain the request's entries into a job-shaped row, so the feed's own weather call
gets booked against nobody and the request's flush finds an empty meter. For a sync job the unit of
work is the **request**, and the request's context (this user, this trip, this session) is better
attribution than anything the job could reconstruct. The test suite caught this; it would have been
a live mis-billing.

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

## 7. Derived views & the admin surface

ADMIN.md stays authoritative for everything under `/admin`; this section extends its §7.1 with
decisions made 2026-07-13. All cost UI is gated by `costs_view` (which does not exist yet).

### 7.1 Rollup (deferred until there is a bill)

A nightly rollup job → `cost_daily` (per user_id × category × vendor × day), which is where:

- **amortization** spreads a cached artifact's cost over its consumers,
- **region capex** spreads `actor_kind = system` spend over the region's active users,
- the **infra allocation rate** (monthly bill ÷ total `cpu_ms`) is applied,
- `admin_emulated` rows are excluded from product metrics (ADMIN §2.4).

### 7.2 Overview strip on `/admin` (ships with the ledger)

The dashboard (`App\Admin\Queries\DashboardStats`, ADMIN §5) gains a cost strip — the
glance-and-sleep-at-night view. At pilot scale these are direct aggregates over `cost_events`
(partition-pruned); once `cost_daily` exists, month/all-time read from it instead.

1. **Spend today** vs the daily cap (§8) as a progress bar — the same number the kill-switch
   watches, so the widget and the breaker can never disagree.
2. **Spend this month** + **projected month-end** (linear burn on month-to-date).
3. **All-time total.**
4. **Biggest line item today** (vendor + resource, one line) — the "what is eating the money"
   headline, linking into §7.3.

The strip shows the **whole bill** — `system` and `admin_emulated` included, because the wallet
does not care who spent it. The actor split is one drill-down away; only *product* metrics
(trip-hour etc.) exclude emulated spend (ADMIN §2.4). Day boundary = the cap's day boundary
(one timezone, in config), or the widget and the breaker drift apart at midnight.

### 7.3 Cost explorer (`/admin/costs`, step 3)

The ADMIN §7.1 priority list stands (cost per active trip-hour first — PRD §14.3, open question
4; vendor/tier breakdown; cost-per-recommendation distribution — a €0.40 recommendation is a bug,
conventions/10; cache savings §2.2; breaker states). On top of it, the navigation rule:

- **Every number is a link one level down.** Time-range picker (today / 7 d / 30 d / custom),
  then: category → vendor → resource/SKU → correlation dimension → individual `cost_events`
  rows. No dead-end numbers.
- **"Most costly" top-N tables**, one per dimension: model, SKU, region, `prompt_version`,
  job class, and user (`actor_kind = user` only — the abuse-detection view, §10 LI basis).
  Each row shows share-of-total, not just the absolute — a $2 item is a headline in a $10 day
  and noise in a $1,000 one.
- **CSV export** of any view (accounting wants files, not screenshots).

### 7.4 Total-control checklist (the rest of the cockpit)

What else the admin needs to *control* cost, not just observe it:

- **Kill-switch panel**: current cap values (read-only — caps live in config; changing one is a
  deliberate deploy, not a 2 a.m. slider), today's consumption per cap, which breakers have
  tripped and when, and a manual **"pause paid calls now / resume"** toggle — superadmin,
  audit-logged, same degradation path as the automatic cap (§8).
- **Alerts, not vigilance**: email at 50% / 80% / 100% of the daily cap, and a spend-rate
  anomaly alert (today's burn ≥ 3× the trailing 7-day average at the same hour). The dashboard
  is for mornings; the alerts are for everything else.
- **Free-tier gauge**: Google's free monthly events consumed (10,000 Essentials) — the
  "when do I actually start paying" number, invisible in spend-based views because free-tier
  usage bills $0 while eating runway.
- **Price-sheet status**: active `price_version`, drift-check last run and result (§6.1) — a
  stale or drifting sheet means every number above is quietly wrong.
- **Per-user ceiling view**: users near their daily ceiling (§8), so a looping client is a row
  in a table before it is an incident.

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

## 9. The three defects this replaced — **all fixed in E24**

The old `CostMeter` / `recommendations.cost` skeleton was the right instinct ("measured zero, not
asserted zero") with three real bugs. Each now has a regression test that asserts the *property*,
not the plumbing (`tests/Feature/Cost/CostLedgerTest.php`):

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

**E24 — done (2026-07-13).** The three §9 fixes; `cost_events` (partitioned, no user FK); the
global HTTP + queue flush seams; `config/pricing.php` (2026-07 sheet, **prices still unverified —
§12.1**); the §8 kill-switch with 50/80/100% alerts and a manual pause; the `/admin` overview strip
(§7.2) behind a new `costs_view` permission; the §10 GDPR work (de-identification on a 90-day
schedule and on erasure) with ROPA updated in the same change.

**E25 — done (2026-07-13).** `cost_daily` + the nightly rollup (amortisation, region-capex spread,
product denominators); the `/admin/costs` explorer (§7.3) with drill-down, top-N-by-share, and CSV
export; the control panel (§7.4 — cap status, superadmin pause/resume, free-tier gauge, price-sheet
status, per-user ceilings); the price-drift check (§6.1), which found the cached-input error in
§12.0 on its first run.

**Still deferred, deliberately:** the storage size report and the infra allocation rate (§2.1). Both
need a real infra bill to divide, and inventing an allocation rate before there is one to allocate
would be exactly the fake precision §2.1 exists to refuse.

## 12. Open questions

0. **The drift check already found two (2026-07-13, first live run).** LiteLLM says cached input is
   **10× cheaper** than the 2026-07 sheet assumes — `gemini-3.1-flash-lite` 25,000 µ/1M (we hold
   250,000) and `gemini-3.5-flash` 150,000 µ/1M (we hold 1,500,000). That is the direction the
   sheet's own warning predicted: cached input was set equal to input as a deliberate
   over-estimate, so the error **fails safe** (we over-report spend and the cap trips early). It is
   also *not yet acted on*, on purpose — a feed does not get to reprice the ledger (§6.1). **Action:
   confirm against the billing console, then land a `2026-08` sheet.** Nothing else in the sheet
   drifted.

   Note this only starts to matter when something re-sends context every turn — which is the Phase 3
   chat (§5.1). Today no call has cached input at all.

1. Verify the remaining §6 prices against the billing console; freeze `price_version: 2026-07`.
2. Route-cache design (origin tile × place × mode, TTL) — needed before edge routing lands; it is
   the single biggest lever in §4.
3. Cap values for §8 (proposal: $10/day global, $1/day/user for the pilot).
4. Whether `cost_daily` is EUR-reported at a fixed monthly rate or per-day ECB rate (proposal:
   monthly, dated in config).
5. Reserve `conversation_id` on `cost_events` in the v1 migration for the possible Phase 3
   per-user chat (proposal: yes — §5.1).
