# Travel Companion AI — Product Requirements Document

| | |
|---|---|
| **Product** | Travel Companion AI (working title) |
| **Document status** | Draft v1.0 |
| **Date** | 2026-07-10 |
| **Owner** | Mats Bergsten / Rockstone |
| **Stack summary** | Laravel modular monolith · PostgreSQL + PostGIS + pgvector · Redis + Horizon · React (web/PWA first, native later) |

---

## 1. Vision

Build the first truly **agentic travel companion**: an AI that actively helps travelers discover memorable experiences in the real world, without requiring them to search, plan, or ask.

Today's travel apps (Google Maps, TripAdvisor, Mindtrip, itinerary planners) are reactive — they wait for the user to initiate. This product inverts the model. The AI behaves like an experienced local friend who quietly accompanies you: it understands your context, monitors your surroundings, learns your preferences, and only speaks when it believes something is genuinely worth your attention.

> *"You're about to pass a family-owned vineyard that still produces wine using methods dating back over 200 years. It closes in 40 minutes and is only a six-minute detour. Based on what you've enjoyed earlier on this trip, I think you'll love it."*

**Core philosophy: Don't help people search. Help them discover.**

The system optimizes not for popularity or ratings, but for **future memories**. Instead of asking "What has the highest Google rating?" it asks: *"What will this traveler remember five years from now?"*

### 1.1 Product thesis (one sentence)

> Build a system that continuously creates and evaluates **opportunities**, not one that merely searches for places.

### 1.2 What this product is not

- Not an itinerary planner.
- Not a chatbot with a map.
- Not another POI search app.
- Not a notification machine. **Silence is often the correct answer.**

---

## 2. The core concept: Opportunities, not places

A **place** is static. A cathedral, restaurant, or museum exists whether or not it is relevant.

An **opportunity** is contextual. It exists because the *current moment* makes that place especially valuable:

- The bakery closes in five minutes.
- The afternoon sunlight is perfect for photography at this viewpoint.
- The queue at the museum has almost disappeared.
- A local market only happens today.
- You're about to leave the region without seeing its most iconic hidden location.

```text
Place:
  Sainte-Chapelle

Opportunity:
  "Go now: the light through the stained glass is ideal, the queue is short,
   and you have 90 minutes before dinner."
```

Every part of the system — data model, pipeline, scoring, delivery — revolves around this distinction.

---

## 3. Problem statement & differentiation

**Problem.** Travelers miss the experiences they would have valued most. Discovery today requires active searching, which travelers rarely do at the right moment, in the right place, with the right context. The result: generic top-10 experiences and missed serendipity.

**Why existing tools don't solve it:**

| Tool | What it answers | What it misses |
|---|---|---|
| Google Maps | "What places are near me?" | Timing, personal fit, uniqueness, proactivity |
| TripAdvisor | "What's popular?" | Context, the long tail, the moment |
| Itinerary planners / Mindtrip | "What should my plan be?" | The unplanned, real-time reality |
| LLM chatbots | "Answer my question" | Doesn't act unless asked; hallucination risk |

**Our product answers a harder, more valuable question:**

> "What is worth changing my behavior for, *right now*?"

**The moat** is not the LLM. It is: (1) the long-tail content layer per region, (2) the behavioral feedback loop per user, and (3) the interruption policy that earns trust. All three compound over time and are hard to copy.

---

## 4. Target users

**Primary (MVP):** Independent leisure travelers on city walks and road trips — couples and solo travelers who value authentic, non-touristy experiences and are curious by disposition. Comfortable with location-based apps. Initially EU-based (launch region strategy, §8).

**Secondary (later):** Families, group travel, business travelers with free evenings, expats exploring their own region.

**Explicitly not (for now):** Package tourists, cruise-day-trippers, users who want a full pre-planned itinerary.

---

## 5. Product principles

1. **Discover, don't search.** The system brings opportunities to the traveler; the traveler never has to formulate a query.
2. **Silence is a feature.** A strict notification budget. The product earns the right to interrupt. Most opportunities are discarded or stored, never surfaced.
3. **Evidence, not hallucination.** Every recommendation is backed by evidence from trusted sources. The LLM speaks only from the evidence bundle. Facts (opening hours, prices, distances, existence) never originate from an LLM.
4. **Memories over ratings.** Rank for expected memorability and personal fit, not popularity.
5. **The context sensor is not a surveillance device.** Privacy is an architecture problem, not a policy page (§16).
6. **Quality before proactivity.** Proactive interruption amplifies recommendation quality; it cannot create it. We prove quality in a pull-based experience before we push (§8).
7. **Content density before generalization.** Prove magic in one region with a dense world model before believing scouts generalize globally (§8.2).
8. **Instrument everything, version everything.** Every recommendation records why, from what, under which model/policy version, and what the user did (§15).

---

## 6. The intelligence model: four models of reality

The system maintains a "**Trip Brain**" combining four continuously updated models:

1. **User model** — learned from behavior, not questionnaires: which recommendations they accept/ignore, how far they walk, what they photograph, dwell times, what consistently creates engagement. Bootstrapped via onboarding calibration (§13.2) to solve cold start.
2. **Trip model** — where they are in the journey: day 1 vs. last afternoon, travel day vs. sightseeing day vs. relaxation day vs. road trip. Recommendations adapt accordingly. Its structural entities (Trip, Explore Session, Segment) are defined in §6.6; the tempo classification above is a **Phase 2** capability (it needs continuous context), so in Phase 1 the model is thin — which trip, how far in, what's been seen this trip.
3. **Context model** — current location, movement mode, route, weather, available time window, time of day, fatigue estimate, practical constraints.
4. **World model** — places, events, stories, routes, closures, weather, light, food, hidden things: combined from maps, historical databases, local events, tourism boards, Wikipedia/Wikidata, OSM, local news, weather services (§9).

The agent loop around the Trip Brain:

```text
Observe  -> Where is the user? What are they doing?
Scout    -> What exists nearby or along the route?
Enrich   -> Is it open? Is it special? Is it timely? Is it worth it?
Score    -> Does this match the person and the moment?
Decide   -> Push, cache, watch, ignore, or ask?
Explain  -> Produce a short, human, evidence-based recommendation.
Learn    -> Did the user accept, ignore, save, detour, dismiss, visit?
```

The "agent" is a **structured decision system**, not a free-floating chatbot. Long-term, specialized perspectives (historian, food expert, photographer, nature guide, architecture expert, budget optimizer, local-culture specialist) are implemented as prompts and pipeline stages within this structure — not as autonomous free agents.

### 6.5 Categorisation: two orthogonal axes

Onboarding, `personal_fit`, `novelty`, `repetition_penalty`, and `uniqueness` all depend on a
categorisation scheme. It is **not one category tree** — a single tree cannot express the product's
own thesis, that a tiny fresco chapel and a grand museum are *different opportunities for different
people* even though both are, categorically, a religious building and a museum. Instead, two
orthogonal axes (full design: [TAXONOMY.md](TAXONOMY.md)):

- **Type** (the noun — what a place *is*): a shallow 2-level hierarchy, ~10 domains → ~65 leaf
  types, normalised from OSM/Overture/Wikidata. Drives dedup, `novelty`, and `repetition_penalty`.
- **Appeal facets** (the adjective — why it is *worth changing behaviour for*): a small orthogonal
  tag set (~14: history, architecture, nature, scenic, food & drink, art, craft, spiritual,
  local-life, family, active, offbeat, romantic, educational). A place carries a *set*.

**Taste is learned on facets, not types** — this is the load-bearing choice. Facets are dense and
generalising: a user who accepts a fresco chapel, a Roman ruin, and a medieval gate has revealed
`history + architecture`, which transfers to a place they have never seen an instance of. ~14 facet
weights converge from a handful of signals; ~65 type weights never would — which is what makes cold
start (§11) tractable. The facets deliberately mirror the Phase 3 specialist lenses above, so each
specialist later becomes a facet scorer. Implemented as PHP backed enums (`PlaceType`,
`PlaceTypeDomain`, `AppealFacet`) per [conventions/02-enums.md](conventions/02-enums.md).

### 6.6 Journey structure: Trip, Explore Session, Segment

The three terms operate at different layers and different phases — conflating them is a category
error. The rule:

- **Explore Session** — the atomic Phase 1 unit, and the *only* one the user initiates. "I have 3
  hours from here (optionally heading that way) — find me something." An origin, a time budget, an
  optional direction/destination, a 3–5 item feed, accumulated feedback, and an end. This is the
  spine of the pull-only MVP.
- **Trip** — a container providing cross-session continuity (novelty, the trip model, the digest,
  privacy deletion, and the seed of Phase 3 cross-trip memory). **Implicit-first:** it materialises
  around sessions — a new session attaches to the user's current active trip when it is close in
  space and time (same region, gap under ~3–4 days) or else opens a new one. The user never has to
  "create a trip" before exploring. One optional explicit path exists — a planner may pre-create a
  named trip to enable pre-scouting (§10) — and both paths converge on the same entity (a `source`
  field records `auto` vs. `user`). Lifecycle `planned → active → completed`; "active" begins at the
  first session, "completed" on inactivity or explicitly. At most one active trip per user.
- **Segment** — a **Phase 2** concept: a system-*inferred* tempo-phase of a trip (travel day /
  sightseeing / relaxation) or a route-leg. Both drivers are Phase 2 — tempo inference needs
  continuous background context, and route-legs need corridor scouting (§9.2). **Segments do not
  exist in Phase 1.** A session's *declared* intent ("I have 3 hours to explore") substitutes for
  the inference. This is the answer to "segment vs. session": orthogonal concepts — a session is a
  *request the user makes*; a segment is an *inferred classification of the trip's timeline* — and
  only the session exists yet.

**Scoping rules that follow** (previously undefined — this is what the entities are *for*): novelty
is **trip-scoped** ("three castles this trip"); `repetition_penalty` is **session-scoped** in Phase
1 (no three churches in one 5-item feed), day-scoped later; context events/snapshots are
**session-scoped**. Trip attribution is a *derived* clustering of sessions, so it is recomputable and
the clustering thresholds are low-stakes.

Both `Trip` and `ExploreSession` live in the **Trips** domain module (conventions/01 — no thirteenth
module). The table is `explore_sessions`, never `sessions` (Laravel owns that name for its session
store). `TripStatus` and `ExploreSessionStatus` are Trips-module enums (conventions/02). **`trip_segments`
is not built in Phase 1.**

---

## 7. Success metrics

### 7.1 North star

> **"I would have missed this" moments per trip-day** — recommendations that the user accepted, actually visited (visit-detected or confirmed), and rated positively or saved as a memory.

### 7.2 MVP validation questions

The MVP exists to answer four questions:

1. Can we find better opportunities than Google Maps? (blind side-by-side preference test)
2. Can we learn taste from behavior? (acceptance rate improves within a trip and across trips)
3. Can we interrupt at the right time? (phase 2 — push acceptance rate vs. annoyance/disable rate)
4. Do users say "I would have missed this"? (qualitative + in-app confirmation)

### 7.3 Supporting metrics

- **Quality:** acceptance rate of surfaced opportunities (target: >25% in launch region); visit-through rate; % of accepted recommendations from long-tail content (not in Google Maps top results).
- **Trust:** notification opt-out / Trip Mode abandonment rate; "why did I get this" open rate; push dismissal streaks.
- **Learning:** acceptance rate delta trip 1 → trip 2 for returning users.
- **Cost:** infrastructure + API + LLM cost per active trip-hour (budget: §14.3).
- **Guardrail:** battery complaint rate; location permission grant rate at each tier.

---

## 8. Scope & phasing

> **Key decision (revised from earlier drafts):** the MVP is **pull-based and foreground-only**. No background location, no geofences, no push notifications in Phase 1. The core hypothesis — "our picks beat Google Maps and users say *I would have missed this*" — is fully testable without background machinery, and background location/push is the hardest, most trust-sensitive, most App-Store-fraught part of the product. Proactivity is Phase 2, added only after recommendation quality is proven.

### 8.0 Phase 0 — Concierge test & content foundation (pre-code / parallel with code)

Before trusting the automated pipeline, prove the experience manually:

- **Concierge test:** for 2–3 real trips (founders/friends), a human plays the companion over a messaging app, using unlimited time and any source. If a human with unlimited time cannot produce magic moments, the automated system will not. Output: a validated quality bar and a corpus of "gold" opportunities.
- **Launch region selection:** pick **one region** (e.g., Burgundy or Provence) and build a dense, partly hand-curated long-tail content layer for it (§9.4).
- **Trip replayer:** build the trace-replay harness (§15.2) as one of the first engineering artifacts.

**Exit criteria:** ≥1 "would have missed this" moment per concierge trip-day; curated layer covering the launch region; replayer runs a recorded trace end-to-end.

### 8.1 Phase 1 — Pull-based MVP ("Explore Mode")

**The promise:** *"Open the app while traveling and it will find one thing nearby you would probably have missed."*

The user opens the app and says, in effect: *"I'm exploring for the next 3 hours."* The app then:

1. Shows the best **3–5 opportunities** nearby / along the stated direction, each with a "why now" explanation and visible evidence.
2. Learns from accept / ignore / save / dismiss / visited behavior.
3. Offers a **daily digest**: a morning "today near you" view and evening recap of opportunities that were found but not surfaced (§12.4).

**In scope:**
- Explore Sessions ("I have N hours") as the primary interaction, foreground location only; Trips created **implicitly** around sessions (§6.6) — no mandatory trip-creation step. Optional explicit trip pre-creation for planners.
- Full backend pipeline: context ingestion → scouts → normalize → dedupe → enrich → score → serve (§10).
- Evidence display and "why this suggestion" explainability.
- Feedback capture (explicit + implicit) and category-level preference learning.
- Onboarding taste calibration (§13.2).
- Web/PWA or minimal Expo client (§13.1) — decision gate below.
- Trip replayer + full instrumentation (§15).
- Launch region only; graceful degradation elsewhere ("we don't know this area deeply yet").

**Explicitly out of scope for Phase 1:**
- Background location, significant-change tracking, geofences.
- Push notifications (local or remote).
- WebSockets/Reverb (polling is sufficient).
- Embedding-based taste model (category weights first — §13.3; pgvector installed but the taste-embedding pipeline deferred).
- Voice, chat interface, multi-agent orchestration, bookings.

**Exit criteria:** in the launch region, ≥25% acceptance rate on surfaced opportunities; ≥1 confirmed "would have missed this" per active trip-day across a 20+ user pilot; blind preference test vs. Google Maps "explore" won ≥60/40.

### 8.2 Phase 2 — Proactive companion ("Trip Mode")

Adds the agentic layer on top of proven recommendation quality:

- Explicit **Trip Mode** opt-in: background significant-change location, geofences, local + push notifications.
- Notification decision engine with hard budget (§12).
- Time-sensitive server pushes ("the market you're near closes in 22 minutes").
- Device-side opportunity cache with geofence triggers, offline corridor pre-download (§13.4).
- Route-corridor scouting for road trips.
- Second launch region; embedding-based personalization if category weights have plateaued.
- Native mobile app (or mature RN background-geolocation SDK) — background behavior, battery, and permission UX are make-or-break and will not be treated casually.

### 8.3 Phase 3 — Deep companion

- Multi-perspective "specialist" reasoning (historian / food / photography / nature lenses) in the ranking and explanation pipeline.
- Cross-trip memory and a durable travel profile ("your travel memory belongs to you").
- Voice delivery for driving (CarPlay/Android Auto is where road-trip usage actually lives).
- Regional expansion playbook: repeatable content-densification process per region.

### 8.4 Postponed indefinitely (revisit only with evidence)

Full autonomous multi-agent system · group preference negotiation · hotel/flight/booking integrations · content marketplace · heavy web crawling · complex itinerary planner · social feed · AR mode · wearables.

---

## 9. The world model: sources, scouts, and content strategy

### 9.1 Scouts, not crawlers

Discovery is performed by **scouts** — narrow, deterministic queue workers, each with one job:

```text
NearbyPlaceScout   Known attractions, cafés, restaurants, viewpoints, museums.
RouteDetourScout   Things near the current/planned route, not merely near the location. (Phase 2)
EventScout         Concerts, markets, festivals, temporary exhibitions.
HistoryScout       Stories, historical places, architecture, local legends.
NatureScout        Viewpoints, walks, beaches, parks, waterfalls, sunset spots.
FoodScout          Regional dishes, bakeries, markets, producers, wine/craft places.
UnusualnessScout   Odd, rare, overlooked, low-review/high-interest opportunities.
CuratedScout       The hand-curated regional layer (§9.4). MVP's highest-value scout.
PracticalScout     Toilets, charging, pharmacies, transport disruptions, shelter. (Phase 2)
NewsLocalScout     Temporary openings, closures, local happenings, strikes, alerts. (Phase 2)
```

Every scout implements a common contract and returns candidates in one shared internal format, making sources pluggable without touching the recommendation engine:

```php
interface ScoutSource
{
    public function supports(ScoutRequest $request): bool;
    public function search(ScoutRequest $request): array;
    public function normalize(array $raw): array;   // -> shared candidate format
    public function ttl(): DateInterval;
}
```

**Source adapters (pluggable):** Google Places (Nearby/Text Search, Details, Photos) · Google Routes · OpenStreetMap/Overpass · Overture Maps · Wikipedia geosearch · Wikivoyage · Wikidata SPARQL · Ticketmaster/event APIs · local tourism boards · government open data (e.g., DATAtourisme, Base Mérimée for France) · RSS/local news · curated content · user-saved places.

> **Full source catalog, licensing rules, credibility tiers, and per-phase adapter roadmap: [DATA-SOURCES.md](DATA-SOURCES.md).** Key architectural rule from that document: the canonical `places` database is built **only on open-licensed data**; proprietary APIs (Google Places, etc.) are a live enrichment/verification edge and are never persisted into the world model. Regional Knowledge Packs (DATA-SOURCES.md §8) are the concrete mechanism for the curated layer below.

### 9.2 Scout bounded regions — never crawl the world

A naive design ("every ping: search everything, call every API, call the LLM") is expensive and bad. Instead:

```text
When trip starts:        scout hotel area, planned cities, next route segment.
When user enters region: scout the current H3 tile + neighbors.
When route is known:     scout the corridor around the route. (Phase 2)
When a free window appears: scout options fitting that window.
When weather/time changes:  RESCORE existing opportunities before fetching new ones.
```

### 9.3 Shared geo-tile caching (core design principle)

**Scouting results are cached per H3 tile and shared across all users.** Scouting Beaune once serves every user in Beaune; personalization happens only at ranking time, per user, against the shared tile cache. This is the primary cost-control and latency mechanism, not an optimization to add later.

TTL policy per data class:

```text
Static places:        weeks–months
Opening hours:        daily + verify before recommendation
Events:               hourly–daily by source
Weather:              frequent
News/local alerts:    frequent during active trips in the tile
LLM summaries:        cache until underlying evidence changes
```

### 9.4 Content density strategy (the real moat)

**The hardest problem in this product is not architecture — it is whether the world model has anything special to say in a given place.** No generic API knows that a family vineyard uses 200-year-old methods. Therefore:

- **One region first.** Build a dense long-tail layer for the launch region: local tourism boards, communal/municipal sites, regional blogs and press, local guides, manually verified entries. Target: enough density that any 3-hour exploration session in the region surfaces ≥1 genuinely non-obvious opportunity.
- **CuratedScout is a first-class source**, expected to win the acceptance-rate leaderboard early. Instrument "which scout finds the most accepted opportunities" from day one.
- **Language-aware scouting.** The best evidence for rural France is in French. Scouts must query in local languages; the LLM layer translates and summarizes. This is a hard requirement, not an i18n nicety.
- **Densification playbook.** Document the per-region process (sources, curation effort, hours required) during Phase 0–1 so Phase 3 expansion is a repeatable operation, not a rediscovery.

### 9.5 Computing "unusualness"

"Unusual" must never mean "the LLM says it is hidden." It is computed from signals:

```text
Low tourist saturation      Few mainstream reviews, but strong niche evidence.
Semantic distinctiveness    Different from common nearby POIs.
Local specificity           Mentioned in local guides, municipal pages, niche blogs.
Temporal rarity             Market, concert, seasonal opening, special light/tide.
Historical/cultural density Wikipedia/Wikidata/local-history mentions.
Personal novelty            User hasn't done something similar recently.
Detour-to-payoff ratio      Small effort, high memorability.
```

A tiny chapel with no reviews but a rare fresco should outrank a famous museum for a user who loves medieval architecture. That is the whole point — and it is computable precisely because taste is expressed over **appeal facets** (§6.5, [TAXONOMY.md](TAXONOMY.md)) rather than place types: the chapel and museum share no type but differ sharply in facets, and `uniqueness` draws partly on how rare a facet combination is for the tile.

### 9.6 Entity resolution (first-class subsystem)

The same place appears in Google (place_id), OSM (node/way), Wikipedia (article), Wikidata (QID), and tourism sites — with name variants, multilingual names, and coordinate drift. Reconciling these into one canonical `place` is a real engineering problem, not a pipeline line-item:

- Eager canonicalization per tile: fuzzy name matching + distance + category signals.
- Persistent **cross-source ID mapping** per canonical place.
- Conflicts (diverging opening hours, coordinates) resolved by source-credibility ranking; disagreement lowers the place's confidence score.
- `places` (stable, canonical, deduped, long-TTL) is strictly separated from `opportunities` (ephemeral, context-bound, TTL'd, cheap to discard and regenerate). The opportunities table must never become a junk drawer.
- **Licensing consequence (see [ODBL-REVIEW.md](ODBL-REVIEW.md)):** because conflation includes OSM data, the conflated geo-core (`places_core`: names, geometry, categories) is an ODbL Derivative Database — it is designed as a publishable open layer from day one, with all proprietary value (curated content, packs, scores, user signals) in separate independent tables keyed by `place_id`. A public dump job and in-app attribution screen are Phase 1 requirements.

---

## 10. The candidate pipeline

Everything interesting happens because of **events**, not synchronous API requests. Every new capability is a new listener or worker, not a rewrite.

**Event vocabulary (grows over time):**

```text
TripStarted, ExploreSessionStarted, LocationChangedSignificantly, UserEnteredCity,
UserStartedWalking, UserStoppedFor10Minutes, RouteChanged, WeatherChanged,
CandidateExpiresSoon, UserAcceptedSuggestion, UserIgnoredSuggestion,
UserSavedSuggestion, UserVisitDetected, UserAskedQuestion, UserReachedHotel,
UserIsNearSavedOpportunity (Phase 2), TripEnded
```

**Pipeline:**

```text
 1. Ingest context      "User is walking in Beaune at 15:22, has 2 hours free."
 2. Determine region    Current tile(s), route corridor, next destination, hotel area.
 3. Run scouts          Check tile cache first; run only stale/missing scouts.
 4. Normalize           All sources -> shared candidate format.
 5. Deduplicate         Entity resolution against canonical places (§9.6).
 6. Enrich              Opening hours, price, route friction, photos, credibility.
 7. Embed               Semantic representation stored (pgvector); used for
                        dedup/distinctiveness in MVP, taste-matching in Phase 2+.
 8. Score               Composite score (§11).
 9. Decide              Serve in feed / hold for digest / watch / discard.
                        (Phase 2 adds: push now / register geofence.)
10. Learn               Feedback updates profile and future ranking.
```

**Opportunity state machine (makes the system debuggable):**

```text
RAW_CANDIDATE -> NORMALIZED -> ENRICHED -> SCORED
   -> { SERVED | DIGEST | WATCHING | DISCARDED }
SERVED -> { ACCEPTED | IGNORED | DISMISSED | SAVED | EXPIRED }
ACCEPTED -> { VISITED | ABANDONED }
```

**Latency budget.** Opening Explore Mode must feel instant even though full scouting can take 30–60s:

- Serve cached tile results **immediately** (<2s perceived).
- Refine progressively in the background; the feed updates as enrichment/scoring lands.
- Pre-scout predictable regions (hotel area, stated next destination, tomorrow-morning area) before the user asks.

---

## 11. Ranking & scoring

Ranking is never "nearest + highest rating." Composite score, v1:

```text
OpportunityScore =
    personal_fit       * 0.30
  + uniqueness         * 0.20
  + temporal_urgency   * 0.15
  + route_fit          * 0.15
  + novelty            * 0.10
  + confidence         * 0.10
  - friction_penalty        (distance, queue, cost, weather, effort)
  - interruption_penalty    (would this be annoying right now?)  [Phase 2]
  - repetition_penalty      (too many churches/cafés today?)
```

**Rules:**

- Weights are v1 heuristics. **All sub-scores are stored per recommendation** (§15) so weights can be fit offline against real acceptance data later. `scoring_model_version` is recorded on every score.
- **`personal_fit` is computed over appeal facets** (§6.5, [TAXONOMY.md](TAXONOMY.md)): roughly the match between the user's learned facet weights and the opportunity's facet set. Learning on ~14 shared facets rather than ~65 place types is what lets a handful of signals generalise to unseen places.
- **Cold-start handling (required, not optional):** `personal_fit` is undefined for a new user — exactly when we must impress them. Until sufficient signal exists: (a) seed the user's facet weights from onboarding calibration priors (§13.2), (b) re-weight toward `uniqueness + temporal_urgency + confidence`, (c) diversify the served set across facets/types to maximize learning per session.
- `confidence` reflects source credibility, freshness, and cross-source agreement — never LLM certainty.

---

## 12. Delivery & the notification decision engine

### 12.1 Phase 1 delivery: the feed and the digest

- **Explore feed:** 3–5 opportunities per session, each with title, "why now" summary, friction (walk/detour minutes, price band, queue risk), evidence links, and map. Never an infinite list — scarcity is part of the product.
- **Daily digest (§12.4)** as the pressure-release valve for good-but-not-urgent finds.

### 12.2 Phase 2: notification policy (a separate product, deterministic first)

The LLM never decides freely when to interrupt. A deterministic policy layer gates everything:

```text
Hard gates (all must pass):
  Trip Mode enabled · not in Do-Not-Disturb window · not driving unless voice mode
  · outside cooldown period · confidence > threshold · currently open/available
  · detour within user tolerance · evidence fresh enough
  · category not recently rejected by this user

Soft boosts:
  time-sensitive · along current route · matches strong preference
  · rare/unique · ideal weather/light right now · last chance before leaving region
```

**Budget v1:** max 3 proactive pushes/day; max 1 per 60–90 minutes. Urgent exception only if `confidence > 0.85 AND urgency > 0.85 AND personal_fit > 0.75 AND detour < user threshold`.

Every push records which `notification_policy_version` allowed it, enabling offline questions like *"would policy_v3 have avoided the annoying push policy_v2 sent?"*

### 12.3 Phase 2: delivery channels

```text
Local notifications:   geofence-based, time-based, offline-capable (device-triggered
                       from the downloaded opportunity cache).
Push (FCM/APNs):       server-discovered, time-sensitive, personalized
                       ("the market near you closes in 22 minutes").
Foreground realtime:   Reverb/WebSocket only while app is open. Never relied on
                       for background behavior.
```

### 12.4 The digest release valve

Opportunities that don't clear the push/feed bar don't die — they surface in a morning "today near you" brief and an evening recap. This (a) lowers the pressure on each individual interrupt decision, (b) gives the learning loop far more labeled exposure, and (c) creates a daily habit surface without notification cost.

---

## 13. Client applications

### 13.1 Client strategy

- **Phase 1:** React web app / PWA (or minimal Expo shell) — foreground geolocation, no app-store friction, fastest iteration. Sufficient because Phase 1 is pull-only. *Decision gate at Phase 1 start: PWA vs. Expo shell, based on pilot-user install friction tolerance.*
- **Phase 2:** background behavior, battery, permission UX, and notification quality are make-or-break — move to native Swift/Kotlin **or** React Native with a mature native background-geolocation SDK. This decision is explicitly deferred until Phase 1 proves quality.

### 13.2 Onboarding taste calibration (cold start)

A ~60-second calibration at first launch: the user picks between pairs/sets of concrete example experiences (photo + one line — "tiny fresco chapel" vs. "grand art museum"; "market food stall" vs. "starred tasting menu"), *never* abstract questions ("do you like museums?"). Each pair is constructed to separate **appeal facets** (§6.5), so the output is a set of **facet prior weights** that seed `personal_fit` and are rapidly overwritten by behavior. Onboarding and behavioural learning therefore operate on the same representation.

### 13.3 Learning from behavior (honest about signal quality)

- **Golden label:** detected/confirmed **visits** (did they actually go?). Everything else is weak evidence.
- **Ignores are ambiguous** — didn't see vs. saw-and-rejected vs. interested-but-busy. Weight accordingly; provide a one-tap "not my thing" affordance to convert ambiguity into signal.
- **Phase 1 learner:** **facet-level** preference weights (§6.5, [TAXONOMY.md](TAXONOMY.md)) — the primary taste signal — plus **type/domain-level** habituation for `novelty`/`repetition_penalty`, and simple per-user thresholds (walking tolerance, price band). No embedding-based taste model until facet weights demonstrably plateau (expected: not before Phase 2). pgvector is installed from day one (cheap) and already used for dedup/distinctiveness; taste-matching is a flag flip later, not a migration.
- Delayed reward matters: saves, photos at the location (if permitted), and revisit intent are memorability signals; log them even before they feed the model.

### 13.4 Phase 2 mobile modules

```text
1. Trip Mode          Explicit opt-in switch for companion behavior.
2. Location manager   Permissions, foreground/background tiers, geofences.
3. Context summarizer Sends meaningful context changes — never a raw GPS stream.
4. Opportunity cache  Offline-first: pre-downloads the route corridor's
                      opportunities (rural dead zones are the norm, not the edge case).
5. Notification handler  Local + push, cooldowns, budget enforcement.
6. Feedback collector    Opened, ignored, saved, dismissed, navigated, visited.
7. Companion UI          Feed, map, saved moments, digest. (Chat/voice: Phase 3.)
8. Privacy controls      Pause, delete trip, precision levels, disable learning.
```

**Location power tiers (Phase 2):**

```text
High accuracy:   only when app is open, navigating, or explicit Trip Mode active.
Medium:          while walking/exploring.
Low power:       significant-change location, geofences, coarse region changes.
No tracking:     at home, outside trips, when the companion is paused.
```

The phone sends **meaningful context changes**, the backend scouts ahead, the phone caches nearby opportunities and triggers local/geofence moments itself. Never "GPS every 5 seconds → backend reasons → push."

---

## 14. System architecture

### 14.1 Modular Laravel monolith

No microservices on day one. Split a service out only when a part genuinely needs it (e.g., a Python embedding/ranking service later). Clean domain boundaries from the start make that split cheap.

```text
Mobile/Web app
   |
   v
Laravel API
   +-- Auth (Sanctum) / users / trips / profiles
   +-- Context ingestion
   +-- Recommendation API
   +-- Agent orchestration
   +-- Notification policy            [Phase 2]
   +-- Admin / curation / analytics
   |
   +-- Queue workers (Redis + Horizon)
   |      +-- Scouts (§9.1)
   |      +-- Enrichment workers
   |      +-- Ranking workers
   |      +-- Notification workers    [Phase 2]
   |
   +-- PostgreSQL + PostGIS + pgvector
   +-- Redis (queues, cache, rate limits, ephemeral context)
   +-- S3-compatible object storage (raw source snapshots, images, traces)
   +-- External APIs (§9.1)
```

**Module structure:**

```text
app/
  Domain/
    Trips/  Context/  Profiles/  Opportunities/  Places/
    Recommendations/  Notifications/  Sources/  Agent/
    Feedback/  Privacy/  Curation/
  Jobs/
    Scouts/       NearbyPlaceScoutJob, EventScoutJob, HistoryScoutJob,
                  UnusualnessScoutJob, CuratedScoutJob, RouteScoutJob [P2]
    Enrichment/   EnrichOpportunityJob, VerifyOpeningHoursJob,
                  CalculateRouteFrictionJob, GenerateEmbeddingJob
    Ranking/      ScoreOpportunityJob, DecideRecommendationJob
    Delivery/     SendPushNotificationJob [P2], RegisterGeofencePayloadJob [P2]
  Services/
    TripBrain, OpportunityScorer, NotificationPolicy,
    SourceRegistry, EntityResolver, AgentOrchestrator, TileCache
```

### 14.2 Data model (core entities)

```text
users, profiles, profile_signals
trips                         (implicit-first container — §6.6)
explore_sessions              (the Phase 1 interaction unit — §6.6; NOT `sessions`, Laravel owns that)
trip_segments                 [Phase 2 — inferred tempo-phase/route-leg; not built in Phase 1]
context_events, context_snapshots   (session-scoped)
source_items                  (raw normalized candidates, per source)
places                        (canonical, deduped — §9.6; carries type + type_domain +
                               facets[] + raw source_tags + taxonomy_version — §6.5, TAXONOMY.md)
place_source_ids              (cross-source ID mapping)
opportunities                 (ephemeral, context-bound, TTL'd)
opportunity_evidence
recommendations               (what was actually served, with full trace;
                               carries explore_session_id + denormalized trip_id — §6.6)
recommendation_feedback
notification_budget           [Phase 2]
scout_runs, agent_runs        (observability)
tiles / tile_cache_state      (H3 shared cache bookkeeping — §9.3)
curated_items                 (the hand-built regional layer — §9.4)
```

**Opportunity object (canonical shape):**

```json
{
  "id": "opp_123",
  "place_id": "plc_456",
  "type": "ephemeral_detour",
  "title": "Small jazz concert in a courtyard",
  "summary": "A local trio starts in 35 minutes, 7 minutes from your current route.",
  "location": { "lat": 48.8566, "lng": 2.3522 },
  "time_window": { "starts_at": "2026-07-08T18:30:00+02:00", "ends_at": "2026-07-08T20:00:00+02:00" },
  "friction": { "walk_minutes": 7, "detour_minutes": 11, "price_estimate": "€€", "queue_risk": "low" },
  "scores": {
    "personal_fit": 0.86, "uniqueness": 0.74, "temporal_urgency": 0.91,
    "route_fit": 0.7, "novelty": 0.8, "confidence": 0.82,
    "composite": 0.79, "scoring_model_version": "v1"
  },
  "source_evidence": [
    { "source": "event_api", "url": "...", "retrieved_at": "...", "excerpt": "...", "credibility": 0.9 }
  ],
  "delivery_policy": { "can_push": true, "can_geofence": true, "max_notification_priority": "medium" },
  "expires_at": "2026-07-08T18:40:00+02:00"
}
```

### 14.3 Cost model (first-class requirement)

Per-context fan-out to paid APIs + LLM calls can silently reach dollars per user-hour. Controls, from day one:

- **Budget target:** define and track a cost ceiling per active trip-hour (initial target to be set in Phase 1; instrument before targeting).
- **Shared tile cache (§9.3)** is the primary lever — most scout work must be cache hits.
- Cost logged **per recommendation** (which API calls, which LLM calls, token counts) alongside the decision trace.
- Rate limits and per-source circuit breakers in `SourceRegistry`.
- LLM summaries cached until evidence changes; cheap/fast model tier for routine summarization, capable tier only for comparison/decision support.

### 14.4 The LLM layer: reasoning, never a database

**Use the LLM for:** query generation for scouts · interpreting local articles (incl. translation) · summarizing why something matters · comparing competing opportunities · explaining "why now" · the companion voice/wording · sanity-checking whether something is genuinely unusual given the evidence.

**Never use the LLM as source of truth for:** opening hours · distances · availability · prices · safety · whether something exists · legality/accessibility.

Every LLM output is generated **from an evidence bundle** and the bundle is stored with the recommendation. The final delivery action always passes through the deterministic policy layer. `prompt_version` is recorded on every generation.

### 14.5 API shape (boring and explicit)

The session — not the trip — is what the user initiates, so it is a **top-level** resource; the
server resolves-or-creates the implicit trip (§6.6). (All under `/api/v1` — conventions/04.)

```text
# Explore Sessions — the Phase 1 interaction
POST /api/v1/explore-sessions                       ("I have 3 hours from here, heading that way")
GET  /api/v1/explore-sessions/{session}             (session state + current feed)
GET  /api/v1/explore-sessions/{session}/opportunities
POST /api/v1/explore-sessions/{session}/context-events
POST /api/v1/explore-sessions/{session}/end

# Recommendations
POST /api/v1/recommendations/{recommendation}/feedback
GET  /api/v1/recommendations/{recommendation}/explanation   ("why did I get this?")

# Trips — implicit container; read / rename / delete, not create-then-start
GET   /api/v1/trips
GET   /api/v1/trips/{trip}
PATCH /api/v1/trips/{trip}                           (rename, mark ended)
POST  /api/v1/trips                                  (OPTIONAL — planner pre-creates a named trip)
GET   /api/v1/trips/{trip}/digest                    (morning/evening digest)
DELETE /api/v1/trips/{trip}/location-history         (privacy — trip-scoped)

# Phase 2
POST /api/v1/trips/{trip}/trip-mode/start            [P2 — explicit background companion mode]
```

`POST /trips/{trip}/start` from earlier drafts is gone: in pull-only Phase 1 the first session *is*
the start; an explicit start only means something for Phase 2 Trip Mode.

Context event payload (fields degrade gracefully when absent):

```json
{
  "explore_session_id": "sess_123",
  "timestamp": "2026-07-08T15:22:00+02:00",
  "location": { "lat": 47.024, "lng": 4.839, "accuracy_m": 42 },
  "movement": { "mode": "walking", "speed_mps": 1.2, "heading": 84 },
  "app_state": "foreground",
  "battery": { "level": 0.64, "low_power_mode": false },
  "user_context": { "available_minutes": 90, "companions": ["partner"] }
}
```

Feedback payload:

```json
{
  "event": "accepted",
  "recommendation_id": "rec_456",
  "timestamp": "2026-07-08T15:28:00+02:00",
  "metadata": { "opened_map": true, "started_navigation": true }
}
```

**This feedback stream is the moat.** Treat its completeness as a product requirement.

---

## 15. Instrumentation, versioning & evaluation

### 15.1 Version everything

```text
scoring_model_version · prompt_version · source_adapter_version
· profile_model_version · notification_policy_version
```

Every recommendation records: why it was recommended, which sources/evidence, all sub-scores, which policy allowed delivery, which model/prompt generated the text, and what the user did. Without this, all iteration is guessing.

### 15.2 The trip replayer (highest-leverage dev tool — build first)

Because the system is event-driven, a **replay harness** is nearly free and transforms iteration:

- Record full context-event traces from real trips (including founders driving/walking the launch region).
- Replay any trace through the current pipeline: inspect what would have been scouted, scored, surfaced, and pushed — under any `scoring_model_version` / `notification_policy_version`.
- Maintain a suite of "gold traces" with expected outcomes (from the concierge test) as a regression suite for ranking and policy changes.
- Answers offline: *Would ranking_v5 have surfaced the accepted recommendation sooner? Would policy_v3 have suppressed the annoying push? Which scout finds the most accepted opportunities?*

### 15.3 Coverage honesty

If the pipeline bounds coverage anywhere (top-N cutoffs, skipped scouts, sampling, cache staleness), that is logged on the recommendation trace. "We covered everything" must never be silently false.

---

## 16. Privacy & compliance (architecture, not a policy page)

Built from the start:

```text
Explicit modes           No passive companionship unless the user turns it on
                         (Explore session in P1, Trip Mode in P2).
Precision levels         Precise / approximate / city-level / off.
Sensitive-zone suppression  Never learn from home, work, medical locations.
Trip-level deletion      Delete all raw location history for a trip, on demand.
Short raw retention      Keep raw pings briefly; convert to coarse derived signals.
On-device filtering      Don't transmit when there is no recommendation value.
Explainability           "Why did I get this suggestion?" — always answerable.
Source transparency      Show the evidence behind every recommendation.
Honest permission UX     Ask for background location (P2) only when the user
                         understands the benefit. No dark patterns.
```

**GDPR specifics (launch region is the EU — this is day-one, not later):**

- Article 5 principles: data minimisation, storage limitation, integrity/confidentiality, accountability — reflected in the retention and precision designs above.
- Continuous precise location + inferred behavioral preference profiles is likely **high-risk processing → a DPIA is required before launch**, and the preference profiling needs a documented lawful basis (consent, given the personalization is the product's core value).
- Data export and full account/profile deletion as standard API-level features.

Positioning upside: *"Your travel memory belongs to you"* is a differentiator, not just compliance.

---

## 17. Risks & mitigations

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| 1 | **Empty world model** — generic APIs can't supply the long-tail magic; recommendations feel like Google Maps | High | Fatal | One-region strategy, curated layer, CuratedScout, concierge test before/alongside build (§8.0, §9.4) |
| 2 | Cold start — first trip is unimpressive, no second trip happens | High | High | Onboarding calibration, re-weighted scoring for new users, diversity serving (§13.2, §11) |
| 3 | API + LLM cost blowout | Medium | High | Shared tile cache, per-recommendation cost tracing, TTLs, circuit breakers (§14.3) |
| 4 | Background location kills trust/battery or fails app review (P2) | Medium | High | Pull-only Phase 1; native-quality background stack decided only after quality is proven (§8.1, §13.1) |
| 5 | Notification fatigue destroys the product's core promise | Medium | High | Deterministic budget + digest valve; annoyance metrics as guardrails (§12) |
| 6 | Learning signal too sparse/ambiguous to personalize | Medium | Medium | Visits as golden label, explicit affordances, category weights before embeddings (§13.3) |
| 7 | Entity-resolution errors → wrong hours/locations → trust damage | Medium | Medium | First-class resolver, cross-source agreement in confidence score, verify-before-recommend (§9.6) |
| 8 | LLM hallucination in explanations | Low–Med | High | Evidence-bundle-only generation, no LLM-originated facts, stored bundles (§14.4) |
| 9 | GDPR exposure (location + profiling) | Medium | High | DPIA pre-launch, retention/precision architecture, deletion APIs (§16) |
| 10 | Rural connectivity dead zones (P2) | High | Medium | Offline-first corridor cache, device-triggered local moments (§13.4) |

---

## 18. Open questions

1. Launch region: Burgundy vs. Provence vs. another candidate — decide on curated-source availability + founder access for ground-truthing.
2. Phase 1 client: PWA vs. minimal Expo shell (decision gate at Phase 1 start).
3. Curation tooling: admin UI scope for the curated layer (build minimal in Phase 1).
4. Cost ceiling per trip-hour: instrument first, then set the target (Phase 1, week ~4).
5. Visit detection heuristics in a foreground-only client (dwell + map-open + self-report?).
6. Monetization (subscription vs. premium regions vs. B2B tourism boards) — explicitly out of scope for this PRD; revisit after Phase 1 exit criteria are met.

---

## 19. Appendix A — Recommended stack

```text
Backend:        Laravel modular monolith
Auth:           Laravel Sanctum
Database:       PostgreSQL + PostGIS + pgvector (taste-embeddings deferred to P2)
Queues:         Redis + Laravel Horizon
Scheduling:     Laravel Scheduler
Realtime:       (P2) Laravel Reverb, foreground only
Push:           (P2) FCM cross-platform; APNs direct if needed later
Maps/Places:    Google Places + Routes initially
Long-tail:      OSM/Overpass, Wikipedia geosearch, Wikidata, curated layer, local sources
LLM:            One provider; evidence-based summarization/comparison/wording;
                cheap tier for routine work, capable tier for decisions
Client:         P1: React web/PWA or Expo shell · P2: native or RN + native geolocation SDK
Storage:        S3-compatible object storage
```

## 20. Appendix B — The architecture principle

The product is **not**:

```text
Location -> LLM -> Recommendation
```

It is:

```text
Location/context event
   -> bounded scouting (cached per shared geo-tile)
   -> evidence collection
   -> canonical places + ephemeral opportunities
   -> personalization
   -> interruption policy (deterministic)
   -> recommendation (evidence-grounded, explained)
   -> feedback loop (versioned, replayable)
```

That is the difference between a demo and a defensible product.
