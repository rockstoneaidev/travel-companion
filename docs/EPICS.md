# Epics — Index

| | |
|---|---|
| **Status** | Phase 1 (M1–M3): live — the work plan is on GitHub; this file is the map · Phase 2 (M4–M6): **planned** — issues/milestones not yet created |
| **Created** | Phase 1: 2026-07-12 · Phase 2 plan: 2026-07-14 |
| **Milestones** | [M1 — Stockholm walking skeleton](https://github.com/rockstoneaidev/travel-companion/milestone/1) (due Jul 18) · [M2 — France-ready](https://github.com/rockstoneaidev/travel-companion/milestone/2) (freeze Jul 25; trip Jul 27–Aug 7) · [M3 — Phase 1 complete](https://github.com/rockstoneaidev/travel-companion/milestone/3) · M4–M6 created together with the Phase 2 issues |

Working agreement: pick up an epic by assigning yourself / commenting on the issue; reference
`E<n>`/`#<n>` in commits; an epic's **Done when** is its acceptance test; specs in `docs/` remain
authoritative — epics carry scope, not design.

---

## Phase 1 — M1–M3

Issue numbers match epic codes (E1 = #1 … E19 = #19; later epics continue the pattern, e.g. E22 = #22).

### M1 — Stockholm walking skeleton (thin end-to-end slice on a phone in Liljeholmen)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#1](https://github.com/rockstoneaidev/travel-companion/issues/1) | World-model foundation: places schema, taxonomy enums, ODbL boundary | backend | — |
| [#2](https://github.com/rockstoneaidev/travel-companion/issues/2) | Stockholm ingest: Overture + OSM + Wikidata adapters | backend | 1 |
| [#3](https://github.com/rockstoneaidev/travel-companion/issues/3) | Entity resolution v1 | backend | 1, 2 |
| [#4](https://github.com/rockstoneaidev/travel-companion/issues/4) | Trips & Explore Sessions: domain + API | backend | 1 |
| [#5](https://github.com/rockstoneaidev/travel-companion/issues/5) | Tile cache, scouts & mode-aware coverage | backend | 1, 2 |
| [#6](https://github.com/rockstoneaidev/travel-companion/issues/6) | Reachability gate & travel-time estimator | backend | 1, 4 |
| [#7](https://github.com/rockstoneaidev/travel-companion/issues/7) | Scoring, feed selection & taste learner | backend | 1, 5, 6 |
| [#8](https://github.com/rockstoneaidev/travel-companion/issues/8) | UI foundation & PWA shell | frontend | — (parallel from day 1) |
| [#9](https://github.com/rockstoneaidev/travel-companion/issues/9) | Core screens: session start, feed, detail, empty | frontend | 4, 7, 8 |
| [#10](https://github.com/rockstoneaidev/travel-companion/issues/10) | Replayer, decision traces & cost instrumentation | platform | 7 |
| [#11](https://github.com/rockstoneaidev/travel-companion/issues/11) | Curation pipeline & the stockholm pack | content | 1, 3 |
| [#22](https://github.com/rockstoneaidev/travel-companion/issues/22) | Sign in with Google (Socialite) + mail transport | platform | — |

### M2 — France-ready (in hand in Paris)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#12](https://github.com/rockstoneaidev/travel-companion/issues/12) | Gemini agent layer: evidence-grounded voice | backend | 7, 10 |
| [#13](https://github.com/rockstoneaidev/travel-companion/issues/13) | France source adapters & corridor ingest | backend | 2, 3, 5 |
| [#14](https://github.com/rockstoneaidev/travel-companion/issues/14) | France city packs (content — founder review hours) | content | 11, 12, 13 |
| [#15](https://github.com/rockstoneaidev/travel-companion/issues/15) | M2 screens: map, kept, calibration, offline hardening | frontend | 8, 9 |
| [#16](https://github.com/rockstoneaidev/travel-companion/issues/16) | Edge enrichment & context: Google verify, weather, astronomy | backend | 5, 7 |
| [#17](https://github.com/rockstoneaidev/travel-companion/issues/17) | Privacy plumbing: retention, home zone, deletion, export | platform | 4 |
| [#18](https://github.com/rockstoneaidev/travel-companion/issues/18) | Digest & Journal *(stretch)* | frontend | 7, 15, 16 |
| [#24](https://github.com/rockstoneaidev/travel-companion/issues/24) | Cost ledger, kill-switch & admin cost strip | platform | 10, 12, 16, 17 |

### M3 — Phase 1 complete (post-trip)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#19](https://github.com/rockstoneaidev/travel-companion/issues/19) | Pilot expansion & exit-criteria instrumentation | platform | everything |
| [#25](https://github.com/rockstoneaidev/travel-companion/issues/25) | Cost explorer, rollup & allocation | platform | 24 |

### Critical path & parallel tracks

```text
Backend spine:  #1 → #2 → #3 ─┬→ #5 → #7 → #12
                #1 → #4 → #6 ─┘        ↘ #10
Frontend:       #8 → #9 → #15 (+#18)      (parallel from day 1; mock data until #7)
Content:        #11 → #14                  (founder review-hours — the scarcest resource;
                                            start harvesting week 1)
France:         #13 → #14 · #16 · #17      (M2)
Auth/mail:      #22                        (independent — no dependencies, nothing waits on it)
Cost:           #24 → #25                  (ledger + kill-switch before the trip; explorer after)
```

The France-ready cut line (decided): NOW + session start + detail + KEPT + offline + calibration +
7 packs + French sources + Gemini voice + MAP are **must**; digest/journal/admin dashboards are
stretch. API keys (Gemini, Google Maps Platform) are already in hand — no epic 0.

---

## Phase 2 — M4–M6 (planned)

**The one-line story.** Phase 1 proved the picks (pull-based, foreground-only); Phase 2 earns the
right to *interrupt* — Trip Mode, background location, geofences, push — on top of that proven
quality (PRD §8.2). It answers MVP validation question 3: *"Can we interrupt at the right time?"*
(PRD §7.2). The two make-or-break risks are PRD risks 4 and 5: background location must not kill
trust/battery/app review, and notifications must not destroy the product's core promise. The plan
below is shaped around de-risking exactly those two, in that order.

**Entry gate — nothing starts before the E19 read.** CLAUDE.md constraint 5 ("don't build Phase 2
machinery early") holds until the Phase 1 exit criteria (#19) are evaluated after the France pilot
(trip ends Aug 7, 2026): ≥25% acceptance, ≥1 confirmed "would have missed this" per active
trip-day across a 20+ user pilot, ≥60/40 blind test vs. Google Maps. PRD §13.1 is explicit that
the mobile-stack decision and repo are deferred until quality is proven. The only pre-gate work
allowed is paper: the DPIA revision draft and the mobile-stack evaluation criteria (both inside
E30/E26 below).

**Provisional numbering.** Phase 1's `E<n> = #<n>` invariant cannot blindly continue — repo
numbers #26/#27 are already consumed by PRs. The codes below (E26–E43) are **provisional**: when
the issues are created (in one consecutive batch, at gate time), codes are renumbered to match the
actual issue numbers and this file is updated. Until then, cite this file, not an issue number.

**New track: mobile.** The Phase 2 client is a **separate repository** (native Swift/Kotlin or RN
with a mature native background-geolocation SDK — E26 decides), a pure consumer of `/api/v1` +
Sanctum, implementing the same design system by sharing **tokens + API types, not components**
(PRD §13.1, DESIGN.md is platform-agnostic by design). Any gap the mobile client finds in
`/api/v1` is a backend bug to fix behind the existing boundary — never a client-side workaround.
The web PWA remains the Explore Mode + settings surface; Trip Mode is mobile-only (background
location is exactly what a PWA can't do reliably).

**Explicitly not in Phase 2** (guard the scope): chat/voice and CarPlay (Phase 3 — PRD §8.3,
§13.4 item 7), specialist reasoning lenses, cross-trip memory beyond the existing profile,
trip-plan-driven ingestion and the content surface (VISION §1/§3 — Phase 3 at the earliest),
everything in PRD §8.4.

### M4 — Proactive skeleton ("one honest push in Stockholm")

The Phase 2 walking skeleton, mirroring M1's philosophy: the thinnest end-to-end proactive slice.
**Milestone done when:** with the app backgrounded on a real phone in Stockholm, a time-sensitive
opportunity passes the deterministic policy and arrives as a push that deep-links into detail —
every gate and version recorded on the trace, and the consent/DPIA set covering the whole flow.

| Code | Epic | Track | Depends on |
|---|---|---|---|
| E26 | Mobile stack decision & background-location spike | mobile | gate (E19) |
| E27 | Trip Mode domain: lifecycle, device registry, background context ingestion | backend | gate (E19) |
| E28 | Notification decision engine, budget & user tolerances | backend | E27 |
| E29 | Push delivery: FCM/APNs, receipts & observability | platform | E27, E28 |
| E30 | Privacy for proactivity: DPIA rev 2, consents, ROPA, DPAs | platform | — (parallel from day 1; **blocks M4 exit**) |
| E31 | Mobile app foundation: repo, auth, Explore parity | mobile | E26 |
| E32 | Location manager, Trip Mode switch & context summarizer | mobile | E27, E31 |

**E26 · Mobile stack decision & background-location spike** — the deferred PRD §13.1 decision,
made with evidence, first. Prototype background significant-change location + geofence wake + push
receipt on the candidate stacks (native Swift/Kotlin vs. React Native + mature native
background-geolocation SDK); measure battery drain; walk the iOS/Android background-location
review requirements; design the honest permission UX with E30. Output: decision recorded in the
PRD decisions log, mobile repo scaffolded, token/API-type sharing mechanism chosen.
*Done when:* a hello-world app on both platforms receives a background wake and shows a local
notification, with measured battery numbers, and the stack decision is written down.

**E27 · Trip Mode domain: lifecycle, device registry, background context ingestion** —
`POST /api/v1/trips/{trip}/trip-mode/start` (+stop) per PRD §14.5; device/push-token registry (a
new personal-data table → E30 updates ROPA); a background context-event endpoint accepting the
client summarizer's *meaningful changes* — never a raw GPS stream (PRD §13.4/§13.5); power-tier
semantics server-side (PRD §13.4); `context_source` propagation onto traces (ADMIN §6). The
Phase 1 privacy machinery (E17) extends to the new stream: home-zone coordinates never written,
30-day coarsening, trip-level deletion (`config/privacy.php`).
*Done when:* an emulated background path (ADMIN §6 playback) under Trip Mode produces context
snapshots server-side, nothing inside the home zone is ever stored, and the retention job covers
every new row.

**E28 · Notification decision engine, budget & user tolerances** — PRD §12.2 verbatim: a plain,
testable, versioned PHP `NotificationPolicy` (conventions/10 — not a prompt; the LLM never decides
when to interrupt). Hard gates + soft boosts; budget v1 (max 3/day, 1 per 60–90 min, urgent
exception only above the §12.2 thresholds); `notification_budget` table (PRD §14.2); cooldowns;
`notification_policy_version` on every delivery (conventions/03). `interruption_penalty` goes live
(SCORING §5.3) — it *orders* within the policy-allowed set, never replaces the gates. User
tolerances (detour threshold, DND window) enter through the ScoringModel named-override seam
(SCORING §9.2 — named, bounded, whitelisted). Below-bar finds flow to the digest valve (§12.4).
Replayer extension: answer *"would policy_v3 have avoided the push policy_v2 sent?"*; admin
dry-run against emulated paths.
*Done when:* replaying one gold trace under two policy versions yields a decision diff, and a
deliberately spammy synthetic day is provably capped at 3 pushes.

**E29 · Push delivery: FCM/APNs, receipts & observability** — FCM/APNs adapters behind a port;
`SendPushNotificationJob` on the `realtime` lane (conventions/08 — the dormant lane wakes up);
delivery receipts and open/dismiss tracking into `recommendation_feedback`; deep links into
detail; `delivery_policy.can_push` respected per opportunity (license metadata via
`SourceDescriptor` — not everything *may* be pushed). New outbound hosts → E30 updates
ROPA/PROCESSORS before first send. Stretch: Reverb foreground channel (PRD §12.3 — never relied
on for background behavior).
*Done when:* a server-discovered time-sensitive opportunity reaches a locked phone as a push, its
receipt and dismissal land in feedback, and the trace records both policy and delivery versions.

**E30 · Privacy for proactivity: DPIA rev 2, consents, ROPA, DPAs** — Phase 2 is precisely the
processing the current DPIA says doesn't happen (background location, geofencing — DPIA §8's
review trigger fires). Revise DPIA §2/§3/§6 for background location + push (FCM/APNs are new
recipients with Ch. V transfer analysis); new versioned CONSENT.md wordings for background
location and push, archived per Art. 7(1); honest permission UX (PRD §16 — asked only when the
benefit is understood, no dark patterns) designed jointly with E26/E32; ROPA gains the new tables
(device registry, `notification_budget`, receipts; `trip_segments` when E36 lands) and recipients;
PROCESSORS + `dpa/` entries the controller must sign — no LLM can close those. Precision levels
and the pause switch (PRD §16) exposed as API + settings on the web PWA too.
*Done when:* the legal set is consistent with the shipped Phase 2 code (no Phase 2 items open in
ROPA §9), and consent versions are archived before the first push reaches a non-founder.

**E31 · Mobile app foundation: repo, auth, Explore parity** — scaffold per the E26 decision;
Sanctum token auth; shared design tokens + API types wired cross-repo; Explore Mode parity
(session start, NOW feed, detail, KEPT) as a pure `/api/v1` consumer. This is where the API-first
boundary pays out: zero backend restructuring is the acceptance test of Phase 1's architecture.
*Done when:* a founder runs a full explore session end-to-end on the native app against staging.

**E32 · Location manager, Trip Mode switch & context summarizer** — the PRD §13.4 client modules
1–3 + 8: explicit Trip Mode opt-in UI (permission UX from E26/E30); location power tiers (high
only when open/navigating/Trip Mode; significant-change + geofences at low power; **no tracking**
at home, outside trips, when paused); client-side context summarizer sending meaningful changes
only; battery instrumentation (PRD §7.3 guardrail); privacy controls surface (pause, precision,
trip location deletion).
*Done when:* a phone in a pocket around Liljeholmen produces significant-change context events at
the documented cadence with measured battery cost, and pause verifiably stops transmission.

### M5 — Road-trip grade ("Trip Mode survives a dead zone")

Proactivity that works where road trips actually happen — offline, rural, in motion. **Milestone
done when:** a multi-day trip out of Stockholm runs entirely in Trip Mode: corridor pre-downloaded,
a geofence moment fires in a dead zone, pushes stay within budget, visits are detected passively,
and the whole trip replays in the replayer.

| Code | Epic | Track | Depends on |
|---|---|---|---|
| E33 | Route-corridor scouting & continuous re-aiming | backend | E27 |
| E34 | Offline corridor cache & geofence moments | mobile + backend | E29, E32, E33 |
| E35 | Passive visit detection & the dense golden label | backend + mobile | E32 |
| E36 | Trip model v2: segments, tempo inference, stay-aware horizon & the vibe axis | backend | E27, E33 |
| E37 | Practical & local-news scouts, transit feeds | backend | E28, E29 (for alert pushes) |
| E38 | Automatic home/work inference | backend | E27, E35 |

**E33 · Route-corridor scouting & continuous re-aiming** — `RouteDetourScout` (PRD §9.1 —
*discovery* along a corridor; distinct from the `route_fit` scoring term, which has been live
since Phase 1); corridor scouting of the full planned route ("when route is known", §9.2);
continuous re-aiming of the mode-aware cone as the user moves (§9.2, conventions/12); next-segment
pre-scout at trip start. Tile discipline unchanged: one H3 res-8 cache, shared across users.
*Done when:* an emulated drive along a corridor (ADMIN §6 path playback) keeps the tile cache
populated ahead of the vehicle without blowing per-tile scout budgets.

**E34 · Offline corridor cache & geofence moments** — PRD §13.4 modules 4–5: corridor
pre-download payloads (offline-first; rural dead zones are the norm, not the edge case); device
opportunity cache; `RegisterGeofencePayloadJob` (PRD §14.1); geofence-triggered **local**
notifications computed device-side, fully offline; device-side budget enforcement mirroring the
server budget; cache expiry honoring opportunity TTL and `delivery_policy.can_geofence`.
*Done when:* an airplane-mode phone moved through a pre-downloaded corridor fires a geofence
moment offline, within budget, and syncs its receipts on reconnect.

**E35 · Passive visit detection & the dense golden label** — dwell-based visit detection (PRD
§7.1, §13.3): the thing background location exists for. Blend with Phase 1's explicit confirmation
(which stays, as the confirm/annotate UX); golden-label updates (target 1, η 0.30 — SCORING §4.1)
at the new volume; the north star stops being partly self-reported. Visit events respect home-zone
and sensitive suppression; gold traces extended with detected visits.
*Done when:* a real visit during a trip is detected with no tap, moves facet weights on the trace,
and the north-star dashboard counts it.

**E36 · Trip model v2: segments, tempo inference, stay-aware horizon & the vibe axis** — the trip
model gets thick (PRD §6.2): `trip_segments` (§6.6, §14.2) with inferred tempo-phases (travel /
sightseeing / relaxation day) and route-legs; stay-aware urgency horizon — known/inferred
departure extends `last_feasible_start`, and last-day-everything-urgent falls out for free
(SCORING §4.3); repetition penalty goes day-scoped (SCORING §5.2). Stretch within the epic:
Taxonomy Axis 3 — experience vibe (TAXONOMY §7, ~4 dims) — which only pays off once tempo is real,
read into the scoring context vectors.
*Done when:* replaying a recorded multi-day trip labels its days' tempo plausibly, and the trip's
last day shows the everything-urgent behavior without special-casing.

**E37 · Practical & local-news scouts, transit feeds** — the two Phase 2 scouts from PRD §9.1:
`PracticalScout` (activates TAXONOMY's `practical` type domain: toilet, charging_point, pharmacy,
shelter, transport_hub — near-range only per mode-aware coverage) and `NewsLocalScout` at scale
(closures, strikes, alerts — DATA-SOURCES Phase 2). GTFS/transit + road-closure feeds for the
corridor. Alerts ride E28's urgency path — through the gates, never around them. Every adapter:
`ScoutSource` contract, `SourceDescriptor` license metadata, cost events (conventions/09).
*Done when:* a road closure on the active corridor surfaces as an alert that passed policy, and
practical results appear only at near range.

**E38 · Automatic home/work inference** — PRD §16: the Phase 2 upgrade of the declared home zone,
inferred from background patterns Phase 1 never collected. Proposed zones require user
confirmation before activating; active inferred zones get full declared-zone treatment (the
coordinate is *never written* — the ROPA §8 promise extends, not weakens). DPIA/ROPA touch via
E30's process.
*Done when:* a week of founder background data proposes the real home zone, and no coordinate
inside it was ever stored.

### M6 — Phase 2 complete (interruption quality proven)

| Code | Epic | Track | Depends on |
|---|---|---|---|
| E39 | Second launch region | content | pack pipeline (E11/E14); region decision |
| E40 | Embedding taste model *(gated on facet plateau)* | backend | E35 |
| E41 | Self-hosted routing: OSRM/Valhalla cost lever *(cost-triggered)* | platform | — (Routing port exists) |
| E42 | Interruption quality: metrics, guardrails & Phase 2 exit | platform | everything |
| E43 | Opportunistic sources: BestTime, Flickr density, event APIs, pack mining *(stretch)* | backend + content | E37 |

**E39 · Second launch region** — PRD §8.2's expansion proof: the densification playbook's (§9.4)
first repetition. Region choice is an open decision (see gate decisions below; VISION §1's
demand-signal logic when trip plans exist, founder choice until then). Packs via the CURATION
pipeline, language-aware scouting in the region's language, honest degradation outside packs.
*Done when:* a 3-hour session in the new region clears the §9.4 density bar (≥1 genuinely
non-obvious opportunity) at pilot-grade acceptance.

**E40 · Embedding taste model — gated** — flip only if facet weights have *demonstrably
plateaued* (PRD §8.2, §13.3); pgvector is already in place, so this is a flag flip, not a
migration. Offline evaluation against the facet learner on gold traces before any live traffic;
`scoring_model_version` bump.
*Done when:* the gate read is recorded either way — "facets still moving → carried forward" is a
valid close; if flipped, offline replay shows measurable uplift before enable.

**E41 · Self-hosted routing: OSRM/Valhalla cost lever** — DATA-SOURCES §9 / PRD §10: self-hosted
routing on the OSM extract replaces Google in Stage B behind the existing `Routing` port — a swap,
not a rewrite. Triggered by the cost ledger (E24/25 data says when Google Routes spend justifies
the ops burden), not by the calendar; keep Google as a fallback flag.
*Done when:* Stage B displayed minutes come from self-hosted routing on staging at spot-checked
parity with Google, and cost per trip-hour drops on the dashboard.

**E42 · Interruption quality: metrics, guardrails & Phase 2 exit** — instrument MVP question 3
(PRD §7.2): push acceptance vs. annoyance/disable rate; the §7.3 trust and guardrail set —
notification opt-out and Trip Mode abandonment, push dismissal streaks, "why did I get this" open
rate, battery complaint rate, location-permission grant rate per tier. Admin dashboards (ADMIN
extension), policy A/B over the replayer, and the Phase 2 exit read itself.
*Done when:* "can we interrupt at the right time?" is answerable from dashboards over a real
pilot's Trip Mode data, and the exit criteria (below) have a measured verdict.

**E43 · Opportunistic sources** *(stretch)* — the DATA-SOURCES Phase 2 tail, adopted selectively:
BestTime evaluation (fills `queue_risk`), Flickr photo-density (corroborates unusualness),
Eventbrite/Bandsintown, tides/aurora where regions warrant, batch Reddit/YouTube mining into pack
builds (CURATION). Each needs a `SourceDescriptor`, licensing review, and a ROPA check before
first call.
*Done when:* each adopted source demonstrably lifts a sub-score on traces; each rejected one has a
one-line reason in DATA-SOURCES.

### Critical path & parallel tracks (Phase 2)

```text
Gate:      E19 read (post France trip, ~mid-Aug) → everything below
Mobile:    E26 → E31 → E32 ─┬→ E34 · E35        (the new track; battery + permission UX
                            │                    are make-or-break, PRD risk 4)
Backend:   E27 → E28 → E29 ─┘                    (the interruption spine, PRD risk 5)
           E27 → E33 → E36
Privacy:   E30                                   (parallel from day 1; M4 cannot ship
                                                  without it — consent before first push)
Scouts:    E37 · E38                             (M5)
M6:        E39 · E40[gated] · E41[cost-triggered] · E42 → exit read
```

**The road-trip-grade cut line (proposed):** E26–E35 + E42's core dashboards + E36's stay-aware
horizon + E37's PracticalScout are **must**; the vibe axis, GTFS feeds, E38, E40 (gate may not
fire), E41 (trigger may not fire), E43, and Reverb foreground realtime are **stretch**.

### Decisions needed at the Phase 2 gate

1. **Mobile stack** — native Swift/Kotlin vs. RN + native background-geolocation SDK (E26's
   output; PRD §13.1 defers it to exactly this moment).
2. **Second launch region** — where E39 points (founder choice; no demand signal exists yet).
3. **Phase 2 exit criteria numbers** — the PRD gives the shape (§7.2 Q3, §7.3) but no targets.
   Proposed, following the PRD's own instrument-first pattern (§18.4): **(a)** push acceptance
   rate ≥ *target set after 2 weeks of instrumented Trip Mode data* with a floor discussion at
   ~15%; **(b)** Trip Mode disable/abandonment < 10% of users who enabled it; **(c)** battery
   complaint rate ≈ 0 in the pilot group; **(d)** a meaningful share of north-star moments
   originating proactively (push/geofence) rather than pull. *These are proposals, not PRD — they
   need a founder decision before M6 closes.*
4. **Embedding gate read** — have facet weights plateaued? (Decides whether E40 is real work or a
   recorded no-op.)
