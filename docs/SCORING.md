# Travel Companion AI — Scoring Model

| | |
|---|---|
| **Document status** | Design v1.0 (`scoring_model_version: v1`) |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §9.3, §9.5, §10, §11, §12.2, §13.2–13.3, §15 · [TAXONOMY.md](TAXONOMY.md) §4–5 · [DATA-SOURCES.md](DATA-SOURCES.md) §1.2 |

---

## 1. Why this document exists

PRD §11 gives the composite formula and its weights, but each input (`personal_fit`, `uniqueness`,
`temporal_urgency`, `route_fit`, `novelty`, `confidence`, and the penalties) was only asserted to be
"a 0–1 number." This document defines every one of them: the v1 formula, its constants, where its
inputs come from, and how it degrades when data is missing. Every constant here is a defensible
starting guess, not a claim of truth — the point is that each one is **named, stored, and
replayable** (§9), which converts a black box into a fittable model.

The weight *vectors* and their context-gating stay in PRD §11; this document is authoritative for
everything below the vector — the sub-score formulas — plus the cold-start weight interpolation
(§6), which PRD §11's cold-start rule (b) delegates here.

---

## 2. Principles

Five structural decisions matter more than any constant below.

### 2.1 Feasibility is a gate, not a score

The **reachability gate** (PRD §10 step 8) decides *membership*; scoring decides *ordering* over the
reachable set. Never encode "can't get there before it closes" as a low score. This is what keeps
sub-scores interpretable and offline-fittable — without it, `friction_penalty` gets abused to
express disqualification and its scale becomes unanswerable.

Two further **evidence gates** live at the *Decide* step (PRD §10 step 10), not in scoring — both
are membership rules, not soft scores:

- **`confidence < 0.25` → held** (`WATCHING` / digest), never served in the feed.
- **Non-Tier-D corroboration required.** An opportunity may only be **served or digested** if it has
  at least one **non-Tier-D** source establishing that it exists (DATA-SOURCES §1.2: Tier-D "never
  sole evidence… never establish facts"). A candidate whose *only* evidence is Tier-D (a blog, a
  Reddit thread, a YouTube list) is not a user-facing item — it is a **lead**: routed to the
  corroboration queue (CuratedScout / curator) to find a non-D source, and surfaced only if one is
  found. Tier-D still *enriches and boosts* opportunities that clear this bar; it just can't
  originate one. This is the literal reading of the sole-evidence rule, and it makes the 0.40 Tier-D
  `confidence` cap (§4.6) a secondary safety net rather than the primary guard.

### 2.2 Every sub-score is a pure function of a recorded input struct

Deterministic, side-effect-free, unit-testable, and — critical for the trip replayer (PRD §15.2) —
recomputable from the trace. The trace stores the **raw inputs** (slack minutes, detour minutes, the
six uniqueness signal values, tier counts …), not just the resulting sub-scores, so the replayer can
refit constants *inside* sub-scores against acceptance data, not only the top-level weights.

### 2.3 Each sub-score has a home in the caching hierarchy

This is PRD §9.3 (shared tile cache, personalization only at ranking) made concrete:

| Scope | Sub-scores | Computed |
|---|---|---|
| **Tile** — user-independent, cached, shared | `uniqueness` (all six signals), `confidence` base | at scout/enrichment time |
| **User** — session-independent | facet weights, tolerances, novelty counters | on feedback events |
| **Session** — rank time only | `temporal_urgency`, `route_fit`, `friction`, `repetition`, composite | per request; cheap arithmetic |

All expensive signals are tile-scoped. Per-user work at rank time is a handful of multiplications.

### 2.4 Penalties are weighted terms on the same scale; repetition is selection-time

Each penalty is a `[0,1]` raw value times a weight: `0.25 × friction_raw`,
`0.15 × repetition_raw`, `0.20 × interruption_raw` (Phase 2; raw ≡ 0 in Phase 1). Maximum friction
therefore costs 0.25 — more than any single positive term except `personal_fit` can contribute,
enough to bury a mediocre candidate, while a 0.9-fit unicorn survives real friction. Genuinely
prohibitive friction never reaches scoring (§2.1).

`repetition_penalty` is a property of the **feed**, not the item — "no three churches in one 5-item
feed" depends on what else is being served — so it is applied during feed selection (§7), not as a
static per-candidate score.

### 2.5 Missing data flows into `confidence`, never into zeroed sub-scores

Sparse data is exactly what hidden gems look like: if missing signals silently score 0, the formula
systematically buries the tiny fresco chapel the product exists to surface. Every sub-score defines
behavior for absent inputs (skip-and-renormalize, or a neutral default), logs `inputs_missing[]` to
the decision trace (PRD §15.3), and the *coverage gap* discounts `confidence` (§4.6) instead.
Caveat: "few mainstream reviews" is itself a positive uniqueness signal — *measured-low* is
distinguished from *not-measured* using the tile coverage stats (PRD §15.3).

---

## 3. Shared primitives

All formulas are built from four deterministic primitives (one small pure-PHP support class):

```text
clamp(x)        max(0, min(1, x))
ramp(x, a, b)   0 below a, 1 above b, linear between        — piecewise-linear, debuggable
decay(t, H)     2^(−t / H)                                   — half-life decay
pct_tile(x)     percentile rank of x among places in the same H3 neighborhood (k-ring 1),
                computed once at tile-cache time and cached with the tile
```

Piecewise-linear ramps over sigmoids everywhere except time decay: a v1 heuristic must be easy to
read off a decision trace and easy to explain in a "why this" debug view.

---

## 4. The sub-scores

### 4.1 `personal_fit` — user × facets

Inputs: the user's facet weights `w_f ∈ [0,1]` (0.5 = neutral; one weight per
[`AppealFacet`](TAXONOMY.md)) and the place's facet set `F`.

```text
personal_fit = 0.7 × max(w_f for f in F)  +  0.3 × mean(w_f for f in F)
```

Max-dominant because travel decisions ride on one strong hook ("I *love* frescoes"); the mean term
rewards multi-facet matches without letting a large facet set dilute a strong one. Empty `F` →
0.5 (neutral) and `inputs_missing[]` logged.

**Facet weight learning** (the Phase 1 learner of PRD §13.3, made concrete). Per feedback event,
for each facet of the place: `w_f ← w_f + η × (target − w_f)` — bounded in `[0,1]` by construction.

| Signal | target | η | Rationale (PRD §13.3) |
|---|---|---|---|
| visit detected/confirmed | 1 | 0.30 | the golden label |
| "not my thing" tap | 0 | 0.25 | explicit negative — the affordance exists to earn this weight |
| saved | 1 | 0.15 | strong intent, no ground truth |
| accepted | 1 | 0.08 | weak positive |
| ignored (served, no interaction) | 0 | 0.02 | ambiguous — nearly worthless by design |

Onboarding calibration (PRD §13.2): each pair choice applies `(target 1, η 0.20)` to the chosen
side's separating facets and `(target 0, η 0.10)` to the rejected side's — same representation,
same update rule, immediately overwritable by behavior.

This table belongs to the *learner*, not the scorer: its constants version under
`profile_model_version`, not `scoring_model_version` (§9.3).

Per-user thresholds (walking tolerance, price band) are **not** part of `personal_fit`; they are
inputs to `friction_penalty` (§5.1).

### 4.2 `uniqueness` — the §9.5 signals, combined

PRD §9.5 lists seven signals; two live elsewhere by design (*personal novelty* → `novelty` §4.5,
*detour-to-payoff* → `route_fit`/`friction`). The remaining five, plus TAXONOMY §5's facet-rarity
signal, are each normalized **tile-relatively** — "unusual" means unusual *here* — which also makes
the whole sub-score user-independent and cacheable per tile (§2.3):

| # | Signal (PRD §9.5) | v1 formula | w |
|---|---|---|---|
| u1 | Low tourist saturation | `1 − pct_tile(mainstream review count)` | .25 |
| u2 | Semantic distinctiveness | `pct_tile(mean cosine distance to 10 NN)` — reuses the dedup embeddings (pgvector), no new infra | .20 |
| u3 | Local specificity | `local evidence items / (local + global evidence items)`, +1 Laplace smoothing | .20 |
| u4 | Temporal rarity | one-off event 1.0 · seasonal 0.7 · weekly (market day) 0.4 · evergreen 0 | .15 |
| u5 | Historical/cultural density | `pct_tile(wikidata sitelinks + wikipedia article length + curated-layer flag)` | .10 |
| u6 | Facet-combination rarity | `1 − share of tile places with Jaccard(facets) ≥ 0.5` | .10 |

```text
uniqueness = Σ wᵢ·uᵢ / Σ wᵢ     over the signals actually available (renormalize;
                                 each gap is logged and discounts confidence — §2.5)
```

For u1, *measured-low* (tile has review coverage, this place has few) scores high; *not-measured*
(no review coverage in tile) drops u1 from the sum instead.

### 4.3 `temporal_urgency` — one variable does all the work

The driving quantity is **slack until the last feasible start** for this opportunity, given the
user's remaining time horizon. `last_feasible_start` is read from the opportunity's `time_window`,
whose shape is set by its `OpportunityKind` (PRD §14.2): `event` → the scheduled `ends_at`;
`ephemeral` → a short near-term window (closing soon, light/weather now); `seasonal` → the season's
end; `evergreen` → no inherent window, so slack is bounded only by the horizon below and urgency
stays low by construction. This is the concrete wiring of "kinds drive scoring."

```text
slack            = last_feasible_start − now − travel_time
temporal_urgency = decay(slack, H = 8h)
                   // 30 min → .96 · 2 h → .84 · ~8 h out → .50 · a day+ out → ~.15
special-moment floor: max(above, 0.7) while a light/weather/tide window is open right now
```

**The horizon is phase-dependent, and Phase 1 is the shallower case — state it plainly:**

- **Phase 1 (the common case).** Trips are *implicit* (PRD §6.6, no declared departure), so the
  horizon is the **opportunity's own closing** (opening hours, event `ends_at`, condition window)
  bounded by **end of the session's day**. `temporal_urgency` is therefore essentially *within-day
  closing/now urgency* — exactly what a pull-only "I have 3 hours" session needs. Evergreen places
  with no near closing get low urgency by construction.
- **Stay-aware horizon (enhancement — needs known trip dates).** When a trip has a known/estimated
  departure (an explicit planner trip, or Phase 2 inference), `last_feasible_start` extends to the
  last chance *before leaving the region*, and two behaviors then fall out for free: evergreen slack
  ≈ length of stay → urgency ≈ 0, and the **last day of a trip makes everything urgent** without
  being special-cased. Do not build against these in pure Phase 1 — they only fire once departure is
  known.

### 4.4 `route_fit` — destination context only

Exists only when the session has a route/destination (PRD §11 context-gating: pure-radius drops the
term and renormalizes). Reads `detour_minutes` from `CalculateRouteFrictionJob` — never
`walk_minutes`, which belongs to friction (PRD §11 "geometry has two fields").

```text
detour     = detour_minutes            (t_via_place − t_direct)
allowance  = min(0.35 × budget_slack, walk_tolerance)
             where budget_slack = session budget − time to continue direct to destination
route_fit  = 1 − ramp(detour, 3 min, allowance)      // ≤3 min ≈ on-route ≈ 1.0
```

Relative-to-the-journey by design: a 12-minute detour is nothing on a free afternoon and fatal with
20 minutes of slack. The reachability gate has already excluded candidates whose detour breaks the
budget; `route_fit` orders the survivors by how *structurally on-the-way* they are.

### 4.5 `novelty` — trip-scoped, type-based

Per PRD §6.6 (novelty is trip-scoped) and TAXONOMY §5 (reads `PlaceType`, secondarily domain).
Events counted with a recency half-life so appetite renews on long trips:

```text
n_type   = Σ decay(age_days, 4 days) over same-PlaceType events this trip
n_domain = same, over same-domain-different-type events
event weights: visited 1.0 · accepted 0.7 · saved 0.3 · ignored 0
novelty  = 0.6^n_type × 0.9^n_domain     // 1st castle 1.0 · 2nd 0.6 · 3rd 0.36
```

### 4.6 `confidence` — credibility × agreement × freshness × coverage

Inputs: the evidence bundle with [DATA-SOURCES.md](DATA-SOURCES.md) §1.2 credibility tiers,
cross-source agreement from entity resolution (PRD §9.6), per-claim TTLs from `SourceRegistry`, and
the coverage gaps accumulated under §2.5. Never LLM certainty (PRD §11).

```text
cred     = max tier value present      (A / curated .95 · B .85 · C .70 · D .40)
corrob   = +0.05 per additional independent corroborating source, cap +0.15
conflict = −0.15 per conflicting claim group (hours disagree, coords > 150 m apart, …)
coverage = −0.05 per missing signal group (§2.5), cap −0.15
fresh    = min over critical claims of  1 − 0.5 × ramp(age / ttl, 0.5, 1.0)
confidence = clamp(cred + corrob + conflict + coverage) × fresh
hard cap: Tier-D-only evidence ⇒ confidence ≤ 0.40
```

Freshness is multiplicative because stale Tier-A is still not actionable. The 0.40 cap is
DATA-SOURCES' "boost, never establish" rule expressed numerically — it keeps D-only finds below the
serve floor's comfort zone and below every Phase 2 push threshold (PRD §12.2).

---

## 5. The penalties

### 5.1 `friction_penalty` — absolute costs vs. this user's thresholds

The per-user thresholds of PRD §13.3 (walking tolerance `tol`, default 15 min; price band) live
here. Reads `walk_minutes` only — the final-approach walk — never `detour_minutes` (§4.4).

```text
time_c    = ramp(walk_minutes, 0, 1.5 × tol)     // monotone from zero: "closer is nicer,
                                                  //  among the reachable" (PRD §10)
price_c   = 0 within band · 0.5 one band above · 1.0 two+ above · unknown 0.3
queue_c   = low .1 · medium .4 · high .8
weather_c = outdoor exposure × current-conditions badness   (covered rain + outdoor → ~.7)
effort_c  = ramp(effort level vs. profile) × (1 − w_active) // active users don't feel stairs
friction_raw = clamp(.45·time_c + .25·price_c + .15·queue_c + .20·weather_c + .15·effort_c)
```

Coefficients deliberately sum past 1: saturating-additive, so several moderate frictions ≈ one
severe one. The `(1 − w_active)` cross-term is the taxonomy paying rent inside the friction model.

### 5.2 `repetition_penalty` — a property of the feed (selection-time)

Session-scoped in Phase 1, day-scoped later (PRD §6.6); reads `PlaceTypeDomain` (TAXONOMY §5).
Computed during greedy feed selection (§7) against the items already picked:

```text
repetition_raw(c | picked) = min(1, 0.5 × count of picked items in c's PlaceTypeDomain)
```

### 5.3 `interruption_penalty` — Phase 2 stub

Weight 0.20, `raw ≡ 0` in Phase 1. When it arrives, it only *orders* candidates within the set the
deterministic notification policy (PRD §12.2) already allowed — it never replaces those gates.
Drivers: moving fast, mealtime, notification density in the last hours, engaged-in-activity signals.

---

## 6. Composite, context vectors, and cold start

```text
score(c | picked) = W(context, α) · S(c)
                    − 0.25 × friction_raw
                    − 0.15 × repetition_raw(c | picked)
                    − 0.20 × interruption_raw          [Phase 2; 0 in Phase 1]
```

where `S(c)` is the sub-score vector and `W(context, α)` interpolates between a cold and a warm
vector **within** the session's context (PRD §11's context-gating and its cold-start rule (b),
unified):

```text
W(context, α) = α × W_warm(context) + (1 − α) × W_cold(context)

α   = clamp(n_eff / 20)  but never below α₀
α₀  = 0.4 once onboarding calibration is completed, else 0
n_eff: visit 5 · "not my thing" 4 · save 3 · accept 2 · explicit-ignore 0.25
```

| Term | warm route | warm radius | cold route | cold radius |
|---|---|---|---|---|
| `personal_fit` | .30 | .35 | .05 | .06 |
| `uniqueness` | .20 | .23 | .30 | .35 |
| `temporal_urgency` | .15 | .18 | .25 | .29 |
| `route_fit` | .15 | — | .15 | — |
| `novelty` | .10 | .12 | .05 | .06 |
| `confidence` | .10 | .12 | .20 | .24 |

The two warm vectors are PRD §11's, verbatim. The cold vectors quantify PRD §11 rule (b) — re-weight
toward `uniqueness + temporal_urgency + confidence` — and the cold-radius column is cold-route with
`route_fit` dropped and renormalized, exactly as PRD §11 derives warm-radius from warm-route.
`route_fit`'s weight is identical cold and warm: whether something is on your way is not a matter of
taste. `α₀ = 0.4` because calibration priors make `personal_fit` partially trustworthy from minute
one; ~20 effective signals (≈4 visits, or 2 visits plus a handful of saves/accepts) reach full warm
weighting. `scoring_model_version` records which vector and which α produced every score (PRD §15.1).

---

## 7. Feed selection (greedy, diversity-aware)

The 3–5 item feed (PRD §12.1) is a **menu of independent alternatives, not an itinerary** (PRD §8.1):
each item already cleared the reachability gate on its own, so selection never checks whether the set
*collectively* fits the budget. It is selected greedily, which is where the selection-time
`repetition_penalty` (§5.2), the cold-start diversification requirement (PRD §11 rule (c)), and
duration variety all live:

```text
picked = []
while |picked| < feed_size and candidates remain:
    score every remaining candidate with repetition_raw(c | picked)
    cold sessions (α < 0.7) additionally: skip candidates whose facet set is a subset
        of facets already covered by picked items, unless fewer than 2 candidates remain
    duration variety: prefer spanning short / medium / long total-time buckets
        (travel + typicalDwellMinutes) so the menu fits the budget's SIZE — a 45-min
        budget yields quick wins, a full day yields ambition; a soft tie-breaker among
        near-equal scores, never an override of a clearly better item
    pick the argmax; append to picked
```

The cold-session facet-coverage rule maximizes learning per session — each served item probes facet
weights the session hasn't probed yet — and expires on its own as α grows. The duration-variety
tie-breaker is what lets one small feed serve both "I have 45 minutes" and "I have all day" without
splitting the budget or planning a route.

---

## 8. Validation

**The PRD contains a test vector.** The §14.2 jazz-courtyard example lists all six sub-scores and
`composite: 0.79`. Running this model on it: weighted sum (warm route vector) = 0.8095; friction from
its own `friction` block (`walk_minutes: 7` vs. default tolerance 15 → time_c ≈ 0.31, queue low →
0.1) gives `friction_raw ≈ 0.155`, penalty ≈ 0.04 → **composite ≈ 0.77**, within a couple of points
of the PRD's illustrative 0.79. The 0.25 friction weight is not arbitrary.

**The §9.5 thesis check passes.** The tiny fresco chapel (no reviews where the tile *has* review
coverage → u1 ≈ 0.9, local-source evidence → u3 high, rare facet combination → u6 high) lands around
uniqueness ≈ 0.75 with `personal_fit` ≈ 0.9 for a history+architecture user; the famous museum gets
u1 ≈ 0.1 → uniqueness ≈ 0.35 and fit ≈ 0.5. The chapel wins by a wide margin — for *that* user, which
is the whole point.

**Distribution anchors.** PRD §12.2's urgent-exception thresholds (`confidence > .85 AND urgency >
.85 AND personal_fit > .75`) presume scores near 1 are *rare*. When the score-distribution
dashboards exist (PRD §15), sub-score histograms that pile up near 1.0 mean a ramp constant is
mis-anchored — fix the sub-score, don't bend the weights.

**Gold traces** (PRD §15.2) pin all of the above per `scoring_model_version` once the replayer exists.

---

## 9. Versioning, configurability & fitting discipline

### 9.1 Constants are code-versioned; config only selects

Every number in this document lives in an **immutable constant set per version** (a
`ScoringModelV1` value object, or equivalently a `config/scoring.php` array keyed by version —
shaped per [conventions](conventions/)). `config('scoring.active_version')` selects which set is
live; that selection is the *only* thing config decides. Changing any constant means minting a new
version; old versions are never edited or deleted, because the trip replayer (PRD §15.2) must be
able to reconstruct exactly what any historical `scoring_model_version` computed.

Consequence, stated as a rule: **individual constants are never env-tunable.** A weight tweaked via
`.env` on one machine makes `scoring_model_version` a lie and silently invalidates gold traces. No
constant is ever inlined at a call site either — all access goes through the resolved model (§9.2).

### 9.2 One resolution seam — the plan-ahead for per-user configurability

Scoring functions never read `config()` directly. Each scoring run receives a single resolved
**`ScoringModel`** built by one resolver, and the recommendation trace records that model's
identity. Today the resolver is trivial — active version, no overrides — but the seam is where all
future per-user configurability slots in *additively*:

```text
EffectiveScoringModel = base constant set (scoring_model_version)
                      + named user overrides (Phase 2+; empty in Phase 1)
trace records: base version + override set (verbatim or fingerprint)
```

Two future override kinds, both **named, bounded, and whitelisted** — never free-form constant
edits:

- **Preference knobs** (product features: "price doesn't matter", a "surprise me" slider,
  "prioritise food today"): explicit user intent stored as structured profile settings, each
  mapping to an enumerated override (e.g. `price_c` coefficient → 0; a capped uniqueness weight
  boost). Distinct from *learned* taste — the user said it, we didn't infer it.
- **Fitted per-user/segment weight vectors** (the offline-fitting endgame): stored on the profile
  with their own fit version, applied as a whole-vector override.

The whitelist rule is what keeps offline fitting honest: if every user can be an arbitrary model,
acceptance data no longer has a shared denominator to fit against. Replay of any historical score
is always *base version + recorded overrides*, both from the trace.

### 9.3 Learned state is data, not configuration

Facet weights, tolerances, and novelty counters are per-user *by design* — they are profile **data**
written by the learner, not configuration. Corollary: the learning-rate table in §4.1 (targets and
η per signal) belongs to the **learner**, not the scorer, and versions under
**`profile_model_version`** (PRD §15.1), in its own immutable versioned set per §9.1. The scorer
reads the weights; the learner writes them; the two evolve — and are refit — independently.

### 9.4 Raw inputs in the trace, and fitting order

- **Raw inputs in the trace, not just sub-scores** (§2.2) — so the replayer can refit constants
  inside sub-scores, not only the six top-level weights. Otherwise "weights can be fit offline
  later" (PRD §11) is only a third true.
- **Fitting order when real acceptance data arrives:** first the ramp anchors (they set sub-score
  distributions), then the weight vectors, then the learning rates in §4.1 — each step against the
  gold-trace suite; the first two each mint a new `scoring_model_version`, the third a new
  `profile_model_version`.
