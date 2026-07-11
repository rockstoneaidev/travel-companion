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
  hours from here (optionally heading that way) — find me something." An origin, a time budget, a
  travel mode (walk / bike / drive — `TravelMode`, drives reach, coverage shape, and speed constants:
  §9.2, §10), an optional direction/destination, a 3–5 item feed, accumulated feedback, and an end.
  This is the spine of the pull-only MVP.
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

**Measuring it in Phase 1 (honest caveat).** A foreground-only client cannot *passively* detect
visits, so the golden label comes from **explicit confirmation** ("I'm here" / "Did you go?" / save-
as-memory) plus **foreground proxies** (started navigation, then dwell near the place while the app
is open). The confirmed-visit label is therefore **sparse but high-quality**, and the north star is
**partly self-reported** in Phase 1 — with acceptance and saves as the denser proxy signals it is
read alongside. Passive, dwell-based visit detection arrives with background location in **Phase 2**,
which is what makes the golden label dense. This is a known limit of proving quality in a pull-only
pilot, not a gap to close before Phase 1 (§13.3, §18).

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
- **Launch regions — DECIDED (2026-07-11):** two founder-ground-truthed contexts instead of one
  abstract region:
  1. **Stockholm (test region, immediately):** the founder's home base (Liljeholmen) — daily-use
     test loops to validate the pipeline, app, and curation flow end-to-end before the trip.
  2. **The France-trip corridor (pilot):** the founders' real trip, **July 27 – Aug 7, 2026** —
     Paris → Nantes (2n) → Bordeaux → Toulouse → Nice/Riviera (2n) → Lyon → Dijon/Burgundy →
     Paris (2n). The trip *is* the concierge test and the first live pilot; Dijon puts the PRD's
     own Burgundy-vineyard thesis on the itinerary. Curation is nights-weighted per city —
     pack plan and effort budget in [CURATION.md](CURATION.md) §4.
  Principle 7 ("content density before generalization") is thereby refined: density where the
  founders can *ground-truth it in person*, not density in the abstract.
- **Trip replayer:** build the trace-replay harness (§15.2) as one of the first engineering artifacts.

**Exit criteria:** ≥1 "would have missed this" moment per concierge trip-day; curated layer covering the launch region; replayer runs a recorded trace end-to-end.

### 8.1 Phase 1 — Pull-based MVP ("Explore Mode")

**The promise:** *"Open the app while traveling and it will find one thing nearby you would probably have missed."*

The user opens the app and says, in effect: *"I'm exploring for the next 3 hours."* The app then:

1. Shows the best **3–5 opportunities** nearby / along the stated direction, each with a "why now" explanation and visible evidence.
2. Learns from accept / ignore / save / dismiss / visited behavior.
3. Offers a **daily digest**: a morning "today near you" view and evening recap of opportunities that were found but not surfaced (§12.4).

**The feed is a menu, not an itinerary.** The 3–5 opportunities are *independent alternatives*, each
of which on its own fits within the stated time — the budget is a per-item feasibility **ceiling**,
not a pool split across items. (This product is explicitly not an itinerary planner, §1.2; "how is
the budget divided across items?" is the wrong question — it isn't.) The user picks one; doing it and
re-opening the app yields a fresh menu scored against the **remaining** budget, so multi-stop
afternoons emerge from repeated picks without the app ever committing to a sequence. Per-item
feasibility — round-trip in pure-radius mode, continue-to-destination when a destination is set — is
the **reachability gate** (§10 step 8). The session inputs it reads — origin, `time_budget_minutes`,
optional `heading` / `destination_point` — are defined in §6.6, and that optional direction is what
"along the stated direction" above refers to. The budget also shapes the menu's *composition* — a
spread of quick-win → big-rock durations, so a 45-minute budget surfaces quick things and a full day
surfaces ambition (SCORING.md §7) — but menu size stays 3–5 regardless (scarcity, §12.1).

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

**Exit criteria:** in the launch region, ≥25% acceptance rate on surfaced opportunities; ≥1 confirmed "would have missed this" per active trip-day across a 20+ user pilot (where "confirmed" is the explicit/self-reported visit signal of §7.1, not passive detection); blind preference test vs. Google Maps "explore" won ≥60/40.

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

> **Discovery ≠ scoring.** `RouteDetourScout` is a *discovery* mechanism — it uses a route to
> *find* candidates along a corridor, and it is Phase 2. Do not confuse it with the `route_fit`
> *scoring* term (§11), which merely rates an already-found candidate by how well it fits the user's
> trajectory. Scoring by route fit needs only a route/destination plus friction enrichment
> (`CalculateRouteFrictionJob`, Phase 1-capable), so `route_fit` is active whenever a session has a
> destination — including in Phase 1 — even though corridor *scouting* is not. They are different
> pipeline stages.

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
When a session declares a heading/destination: scout an ahead-weighted cone/corridor, not a disc.
When route is known:     scout the corridor around the full route. (Phase 2 — continuous re-aiming)
When a free window appears: scout options fitting that window.
When weather/time changes:  RESCORE existing opportunities before fetching new ones.
```

**Mode-aware, anisotropic coverage (v1 decision).** Travel mode (walk / bike / drive) never changes
the tile size — one canonical H3 resolution keeps the cache shared, the uniqueness signal meaningful,
and costs accountable (conventions/12). Mode changes **coverage**: how far (reach from
`time_budget × mode speed`), what shape (disc when wandering; cone/corridor when the session has a
heading or destination — full reach ahead, ~40% behind), and **which scouts run at range** — with
distance, only high-payoff scouts (Curated, History, Nature, Unusualness) keep running; food/café
scouting stays near. A café is worth a 300 m detour, a ruined castle 20 km. Per-scout ranges live in
the `SourceDescriptor` (conventions/09); the coverage geometry and mode table live in conventions/12.
Phase 1 covers the road-trip case via the session-declared destination; *continuously* re-aiming the
cone as the user moves is Phase 2.

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

A tiny chapel with no reviews but a rare fresco should outrank a famous museum for a user who loves medieval architecture. That is the whole point — and it is computable precisely because taste is expressed over **appeal facets** (§6.5, [TAXONOMY.md](TAXONOMY.md)) rather than place types: the chapel and museum share no type but differ sharply in facets, and `uniqueness` draws partly on how rare a facet combination is for the tile. How the signals combine into the single 0–1 `uniqueness` value — including which two of the seven live in *other* scoring terms — is defined in [SCORING.md](SCORING.md) §4.2; all signals are tile-relative, so the whole sub-score is user-independent and cached with the tile (§9.3).

### 9.6 Entity resolution (first-class subsystem)

The same place appears in Google (place_id), OSM (node/way), Wikipedia (article), Wikidata (QID), and tourism sites — with name variants, multilingual names, and coordinate drift. Reconciling these into one canonical `place` is a real engineering problem, not a pipeline line-item:

- Eager canonicalization per tile: fuzzy name matching + distance + category signals.
- Persistent **cross-source ID mapping** per canonical place.
- Conflicts (diverging opening hours, coordinates) resolved by source-credibility ranking; disagreement lowers the place's confidence score.
- `places` (stable, canonical, deduped, long-TTL) is strictly separated from `opportunities` (short-lived, context-bound, TTL'd, cheap to discard and regenerate). The opportunities table must never become a junk drawer. ("Short-lived" is the generic property of the table; do not confuse it with the `ephemeral` value of `OpportunityKind` — §14.2.)
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
 5. Embed               Semantic representation (pgvector), generated once per canonical
                        place and cached with the tile. Needed NEXT for semantic dedup and
                        for the tile-scoped distinctiveness signal; taste-matching in P2+.
 6. Deduplicate         Entity resolution against canonical places (§9.6; uses embeddings).
 7. Enrich              Opening hours, price, walk/route friction, photos, credibility, and
                        the tile-scoped `uniqueness` signals (SCORING.md §4.2).
 8. Reachability gate   HARD FILTER (§11): keep only candidates reachable AND
                        experienceable within the remaining time budget —
                        travel + typical dwell + return (pure-radius) or
                        continue-to-destination (destination mode). Unreachable
                        candidates are EXCLUDED here, never merely down-ranked.
 9. Score               Composite score (§11), using the context-appropriate weight vector.
10. Decide              Serve in feed / hold for digest / watch / discard.
                        (Phase 2 adds: push now / register geofence.)
11. Learn               Feedback updates profile and future ranking.
```

Steps 5–7 (embed, dedup, tile-scoped enrichment incl. `uniqueness`) are **user-independent and
cached per H3 tile** (§9.3); only steps 8–9 run per user at request time. The **reachability gate**
(step 8) is what makes "I have 3 hours" actually constrain the feed:
it is a filter, not a scoring weight. Distance then plays a *second, softer* role inside `friction_penalty`
(§11) — "among the ones I can reach, closer is nicer" — with no double-counting, because the gate
decides *membership* and the penalty decides *ordering*.

The gate tests each candidate against the budget as a **per-item ceiling** (the feed is a menu of
independent alternatives, not an itinerary — §8.1); it does not divide the budget across items.
`typical dwell` comes from the candidate's `PlaceType` default (`typicalDwellMinutes()`,
[TAXONOMY.md](TAXONOMY.md) §2), optionally overridden by opportunity signals (a quick photo stop vs.
a guided tour).

**Travel-time strategy (tiered — v1 decision).** The gate needs a travel time for *every* candidate
in scope; per-candidate routing calls would blow the cost model (§14.3). So:

- **Stage A (gate + friction, all candidates):** estimator, free and in-DB — PostGIS distance ×
  mode speed × a path factor. v1 constants (in `config/scoring.php`): walking 4.5 km/h × 1.30;
  cycling 14 km/h × 1.30; driving ~40 km/h effective urban / faster rural × 1.35. Session mode is
  declared (`TravelMode` enum: walk default; bike and drive selectable — Burgundy vineyards aren't
  walkable, Stockholm has city bikes). Mode also drives scout coverage — reach, cone/corridor shape,
  and which sources run at range (§9.2, conventions/12).
- **Stage B (precision where it's shown):** real routing **only** for the 3–5 served items'
  displayed `walk_minutes` and for destination-mode `detour_minutes` — ≤ ~6 Google Routes calls per
  session, edge-only (conventions/09), cached per (place, origin res-9 tile, mode) with short TTL.
- Self-hosted OSRM/Valhalla on the OSM extract replaces Google in Stage B as the Phase 2 cost lever
  (DATA-SOURCES §9); the `Routing` port makes that a swap, not a rewrite.

Estimator error (±20–30%) is acceptable at the gate because the ceiling already includes dwell and
the menu is alternatives, not a schedule; the numbers a user actually *sees* are Stage-B real.

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

Ranking is never "nearest + highest rating." It runs only over candidates that survived the
**reachability gate** (§10 step 8) — scoring orders the reachable set, it does not decide membership.

**There is no single universal weight vector — the vector is context-gated.** Two terms only exist
in some contexts: `route_fit` requires a route/destination (see below), and `interruption_penalty`
is Phase 2 only. So we publish and version (`scoring_model_version`) a vector *per context* rather
than pretend one is phase-agnostic.

**Route/destination context** (a Phase 1 destination-mode session, or any Phase 2 route) — v1:

```text
OpportunityScore =
    personal_fit       * 0.30
  + uniqueness         * 0.20
  + temporal_urgency   * 0.15
  + route_fit          * 0.15      (from detour_minutes — how "on the way" it is)
  + novelty            * 0.10
  + confidence         * 0.10
  - friction_penalty               (queue, cost, weather, effort, final-approach walk)
  - interruption_penalty           (would this be annoying right now?)  [Phase 2]
  - repetition_penalty             (too many churches/cafés today?)
```

**Pure-radius context** (the common Phase 1 case: "I have 3 hours *here*", no direction) — `route_fit`
is undefined (there is no trajectory to fit), so it is dropped and its weight renormalised across the
positive terms — v1:

```text
OpportunityScore =
    personal_fit       * 0.35
  + uniqueness         * 0.23
  + temporal_urgency   * 0.18
  + novelty            * 0.12
  + confidence         * 0.12
  - friction_penalty               (walk_minutes-based: closer is nicer, among the reachable)
  - repetition_penalty
```

**Geometry has two fields, never double-counted.** The opportunity's `friction` object carries both
`walk_minutes` (absolute, from where the user is) and `detour_minutes` (route-relative, only present
with a route/destination). `friction_penalty` reads `walk_minutes`; `route_fit` reads
`detour_minutes`. In pure-radius context `detour_minutes` is null, which is *why* `route_fit` is
absent there — not an arbitrary phase rule.

**Rules:**

- **Every input above is a defined 0–1 quantity, not an assertion.** The per-sub-score formulas, constants, missing-data behavior, and facet-weight learning updates live in [SCORING.md](SCORING.md), which is authoritative for implementation; the vectors above are the summary.
- Both vectors are v1 heuristics. **All sub-scores are stored per recommendation** (§15) — together with their *raw inputs* (SCORING.md §2.2), so both the weights and the constants inside sub-scores can be fit offline against real acceptance data later. `scoring_model_version` records which vector produced a given score.
- **`personal_fit` is computed over appeal facets** (§6.5, [TAXONOMY.md](TAXONOMY.md)): roughly the match between the user's learned facet weights and the opportunity's facet set (SCORING.md §4.1). Learning on ~14 shared facets rather than ~65 place types is what lets a handful of signals generalise to unseen places.
- **Cold-start handling (required, not optional):** `personal_fit` is undefined for a new user — exactly when we must impress them. Until sufficient signal exists: (a) seed the user's facet weights from onboarding calibration priors (§13.2), (b) re-weight toward `uniqueness + temporal_urgency + confidence` — quantified as the cold/warm weight interpolation in SCORING.md §6, (c) diversify the served set across facets/types to maximize learning per session (the selection rule in SCORING.md §7).
- `confidence` reflects source credibility, freshness, and cross-source agreement — never LLM certainty (SCORING.md §4.6).

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

- **Phase 1 — DECIDED (2026-07-11): a single responsive Inertia/React app, installable as a PWA.**
  One codebase serves both surfaces: mobile-first UI installed to the home screen on the trip
  (manifest + service worker, foreground geolocation), and the same UI as a desktop web app —
  explicitly desired, not an afterthought. No Expo shell, no app store. This rides the existing
  Laravel + Inertia 2 + React 19 scaffold directly.
- **Phase 2:** background behavior, battery, permission UX, and notification quality are make-or-break — move to native Swift/Kotlin **or** React Native with a mature native background-geolocation SDK. This decision is explicitly deferred until Phase 1 proves quality.

### 13.2 Onboarding taste calibration (cold start)

A ~60-second calibration at first launch: the user picks between pairs/sets of concrete example experiences (photo + one line — "tiny fresco chapel" vs. "grand art museum"; "market food stall" vs. "starred tasting menu"), *never* abstract questions ("do you like museums?"). Each pair is constructed to separate **appeal facets** (§6.5), so the output is a set of **facet prior weights** that seed `personal_fit` and are rapidly overwritten by behavior. Onboarding and behavioural learning therefore operate on the same representation.

### 13.3 Learning from behavior (honest about signal quality)

- **Golden label:** detected/confirmed **visits** (did they actually go?). Everything else is weak evidence. In foreground-only **Phase 1** a visit is *explicitly confirmed* (an "I'm here" / "Did you go?" tap, or save-as-memory) or inferred from strong foreground proxies (started navigation → dwell near the place) — **sparse but high-quality**; the update rule (target 1, η 0.30) in SCORING.md §4.1 applies to whichever facets the visited place carries. **Passive** dwell-based detection needs background location (Phase 2), which is what turns this sparse label dense.
- **Ignores are ambiguous** — didn't see vs. saw-and-rejected vs. interested-but-busy. Weight accordingly; provide a one-tap "not my thing" affordance to convert ambiguity into signal.
- **Phase 1 learner:** **facet-level** preference weights (§6.5, [TAXONOMY.md](TAXONOMY.md)) — the primary taste signal — plus **type/domain-level** habituation for `novelty`/`repetition_penalty`, and simple per-user thresholds (walking tolerance, price band). The concrete update rule and per-signal learning rates are [SCORING.md](SCORING.md) §4.1. No embedding-based taste model until facet weights demonstrably plateau (expected: not before Phase 2). pgvector is installed from day one (cheap) and already used for dedup/distinctiveness; taste-matching is a flag flip later, not a migration.
- Delayed reward matters: saves, photos at the location (if permitted), and revisit intent are memorability signals; log them even before they feed the model.
- **Judgment call — Phase 1 taste learning runs mostly on *weak* signals.** Because the golden visit label is sparse in a foreground-only pilot (§7.1), most facet-weight movement in Phase 1 comes from onboarding priors plus the weak accept/save signals (η 0.08 / 0.15, SCORING.md §4.1), not the golden visit (η 0.30). We accept this deliberately: it means MVP validation Q2 ("can we learn taste from behavior?") is answered on weak-but-abundant signal until Phase 2's passive visit detection makes the golden label dense. Consequence for the pilot: if taste weights aren't moving, the lever is **more explicit affordances** (surface the "not my thing" / save prompts more), not a longer onboarding — onboarding seeds priors, behaviour must still do the refining.

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
opportunities                 (short-lived, context-bound, TTL'd; carries a `kind` — §14.2)
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
  "kind": "event",
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

**`kind` is an `OpportunityKind`** (`App\Domain\Opportunities\Enums\OpportunityKind`, per
[conventions/02-enums.md](conventions/02-enums.md)) — it classifies the *temporal nature* of the
opportunity, which governs `time_window` semantics, `expires_at`, and which signals dominate scoring.
v1 cases:

| Kind | Meaning | `time_window` | Effect |
|---|---|---|---|
| `evergreen` | a stable place, no inherent time pressure (a viewpoint, a museum) | none (opening hours only) | low `temporal_urgency`; ranked on fit/uniqueness |
| `ephemeral` | a fleeting *now* window — closing soon, short queue now, ideal light/weather now | short, near-term | high `temporal_urgency`; short `expires_at` |
| `event` | a scheduled happening (concert, market day, exhibition run) | fixed `starts_at`/`ends_at` | urgency from the window; expires at `ends_at` |
| `seasonal` | available only within a season/date range (harvest, bloom, seasonal opening) | wide date range | mild urgency until the range nears its end |

Two things that are deliberately **not** kinds: the *geometry* of an opportunity (detour vs.
on-route) lives in `friction`/`route_fit` (§11), not the kind (hence the rename from the earlier
`ephemeral_detour`, which conflated the two); and "last chance before leaving the region" is
`temporal_urgency`'s stay-aware horizon (SCORING.md §4.3) — a property of the trip, not the place.

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

**Concrete v1 retention & suppression policy** (numbers, not adjectives — versioned in
`config/privacy.php` like every other constant set):

- **Raw precise location** (context events, session origins): retained **30 days**, then coarsened
  to H3 res-8 cell + derived signals (movement mode, dwell class); the precise coordinates are hard-
  deleted. Trip-level deletion (§14.5) removes raw *and* derived location data immediately.
- **Recommendation traces** (§15): kept indefinitely for replay, but their location fields are
  coarsened to H3 res-8 on the same 30-day schedule — **except** accounts with explicit research
  consent (founders/pilot users), whose full-precision traces feed the gold-trace suite (§15.2).
- **Sensitive-zone suppression, Phase 1 scope:** a **user-declared home zone** (default radius
  300 m) — no learning signals, no context storage beyond coarse presence, no opportunities served
  inside it. Relevant immediately: Stockholm testing happens from the founder's actual home base.
  *Automatic* home/work inference is Phase 2 (it needs background patterns Phase 1 doesn't collect).
- Facet weights and profile signals persist until account deletion or an explicit "reset my taste
  profile" (both Phase 1 API features).

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

1. ~~Launch region.~~ **Resolved (§8.0):** Stockholm test region + the France-trip corridor
   (Jul 27 – Aug 7, 2026) as pilot; pack plan in CURATION.md §4.
2. ~~Phase 1 client.~~ **Resolved (§13.1):** single responsive Inertia/React app, installable PWA;
   same UI on desktop web.
3. Curation tooling: admin UI scope for the curated layer (build minimal in Phase 1 — sequenced in ADMIN.md; pipeline in CURATION.md).
4. Cost ceiling per trip-hour: instrument first, then set the target (Phase 1, week ~4).
5. ~~Visit detection heuristics in a foreground-only client.~~ **Resolved (§7.1, §13.3):** Phase 1 uses explicit confirmation + foreground proxies (a sparse, high-quality golden label; the north star is accordingly part self-reported in Phase 1); passive dwell detection is Phase 2. Only the exact proxy thresholds remain, to tune against pilot data.
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
LLM:            Google Gemini (DECIDED 2026-07-11; API key in hand), behind the swappable
                LlmClient port (conventions/10). Tiers: cheap/routine (facet tagging,
                translation, summaries) = gemini-3.1-flash-lite; capable (comparison,
                "why now" wording, pack drafting) = gemini-3.5-flash; gemini-3.1-pro
                reserved for escalation. Verify current model IDs at implementation.
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
   -> canonical places + short-lived opportunities
   -> personalization
   -> interruption policy (deterministic)
   -> recommendation (evidence-grounded, explained)
   -> feedback loop (versioned, replayable)
```

That is the difference between a demo and a defensible product.
