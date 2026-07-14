# Epics — Index

| | |
|---|---|
| **Status** | Live — the work plan is on GitHub; this file is the map. Phase 1: M1–M3 · Phase 2: M4–M6 (created 2026-07-14; **work gated on the E19 read** — see below) |
| **Created** | Phase 1: 2026-07-12 · Phase 2: 2026-07-14 · issue numbers match epic codes (E1 = #1 … E45 = #45; #20/21/23/26/27 are PRs, not epics) |
| **Milestones** | [M1 — Stockholm walking skeleton](https://github.com/rockstoneaidev/travel-companion/milestone/1) (due Jul 18) · [M2 — France-ready](https://github.com/rockstoneaidev/travel-companion/milestone/2) (freeze Jul 25; trip Jul 27–Aug 7) · [M3 — Phase 1 complete](https://github.com/rockstoneaidev/travel-companion/milestone/3) · [M4 — Proactive skeleton](https://github.com/rockstoneaidev/travel-companion/milestone/4) · [M5 — Road-trip grade](https://github.com/rockstoneaidev/travel-companion/milestone/5) · [M6 — Phase 2 complete](https://github.com/rockstoneaidev/travel-companion/milestone/6) |

Working agreement: pick up an epic by assigning yourself / commenting on the issue; reference
`E<n>`/`#<n>` in commits; an epic's **Done when** is its acceptance test; specs in `docs/` remain
authoritative — epics carry scope, not design.

---

## Phase 1 — M1–M3

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

## Phase 2 — M4–M6

**The one-line story.** Phase 1 proved the picks (pull-based, foreground-only); Phase 2 earns the
right to *interrupt* — Trip Mode, background location, geofences, push — on top of that proven
quality (PRD §8.2). It answers MVP validation question 3: *"Can we interrupt at the right time?"*
(PRD §7.2). The plan is shaped around the two make-or-break risks, PRD risks 4 and 5: background
location must not kill trust/battery/app review, and notifications must not destroy the product's
core promise.

**Entry gate — code starts only after the E19 read.** CLAUDE.md constraint 5 ("don't build Phase 2
machinery early") holds until the Phase 1 exit criteria (#19) are evaluated after the France pilot
(trip ends Aug 7, 2026). PRD §13.1 explicitly defers the mobile-stack decision and repo to that
moment. The only pre-gate work allowed is paper: the DPIA revision draft (#32) and the
mobile-stack evaluation criteria (#28).

**New track: mobile.** The Phase 2 client is a **separate repository** (native Swift/Kotlin or RN
with a mature native background-geolocation SDK — #28 decides), a pure consumer of `/api/v1` +
Sanctum, implementing the same design system by sharing **tokens + API types, not components**
(PRD §13.1; DESIGN.md is platform-agnostic by design). Any gap the mobile client finds in
`/api/v1` is a backend bug to fix behind the existing boundary — never a client-side workaround.
The web PWA remains the Explore Mode + settings surface; Trip Mode is mobile-only (background
location is exactly what a PWA can't do reliably).

**Explicitly not in Phase 2** (guard the scope): chat/voice and CarPlay (Phase 3 — PRD §8.3,
§13.4 item 7), specialist reasoning lenses, cross-trip memory beyond the existing profile,
trip-plan-driven ingestion and the content surface (VISION §1/§3 — Phase 3 at the earliest),
everything in PRD §8.4.

### M4 — Proactive skeleton ("one honest push in Stockholm")

The Phase 2 walking skeleton, mirroring M1's philosophy. **Milestone done when:** with the app
backgrounded on a real phone in Stockholm, a time-sensitive opportunity passes the deterministic
policy and arrives as a push that deep-links into detail — every gate and version recorded on the
trace, and the consent/DPIA set covering the whole flow.

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#28](https://github.com/rockstoneaidev/travel-companion/issues/28) | Mobile stack decision & background-location spike | mobile | gate (19) |
| [#29](https://github.com/rockstoneaidev/travel-companion/issues/29) | Trip Mode domain: lifecycle, device registry, background context ingestion | backend | gate (19) |
| [#30](https://github.com/rockstoneaidev/travel-companion/issues/30) | Notification decision engine, budget & user tolerances | backend | 29 |
| [#31](https://github.com/rockstoneaidev/travel-companion/issues/31) | Push delivery: FCM/APNs, receipts & observability | platform | 29, 30 |
| [#32](https://github.com/rockstoneaidev/travel-companion/issues/32) | Privacy for proactivity: DPIA rev 2, consents, ROPA, DPAs | platform | — (parallel from day 1; **blocks M4 exit**) |
| [#33](https://github.com/rockstoneaidev/travel-companion/issues/33) | Mobile app foundation: repo, auth, Explore parity | mobile | 28 |
| [#34](https://github.com/rockstoneaidev/travel-companion/issues/34) | Location manager, Trip Mode switch & context summarizer | mobile | 29, 33 |

### M5 — Road-trip grade ("Trip Mode survives a dead zone")

Proactivity that works where road trips actually happen — offline, rural, in motion. **Milestone
done when:** a multi-day trip out of Stockholm runs entirely in Trip Mode: corridor pre-downloaded,
a geofence moment fires in a dead zone, pushes stay within budget, visits are detected passively,
and the whole trip replays in the replayer.

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#35](https://github.com/rockstoneaidev/travel-companion/issues/35) | Route-corridor scouting & continuous re-aiming | backend | 29 |
| [#36](https://github.com/rockstoneaidev/travel-companion/issues/36) | Offline corridor cache & geofence moments | mobile | 31, 34, 35 |
| [#37](https://github.com/rockstoneaidev/travel-companion/issues/37) | Passive visit detection & the dense golden label | backend | 34 |
| [#38](https://github.com/rockstoneaidev/travel-companion/issues/38) | Trip model v2: segments, tempo inference, stay-aware horizon & the vibe axis | backend | 29, 35 |
| [#39](https://github.com/rockstoneaidev/travel-companion/issues/39) | Practical & local-news scouts, transit feeds | backend | 30, 31 |
| [#40](https://github.com/rockstoneaidev/travel-companion/issues/40) | Automatic home/work inference | backend | 29, 37 |

### M6 — Phase 2 complete (interruption quality proven)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#41](https://github.com/rockstoneaidev/travel-companion/issues/41) | Second launch region | content | 11, 14; region decision |
| [#42](https://github.com/rockstoneaidev/travel-companion/issues/42) | Embedding taste model *(gated on facet plateau)* | backend | 37 |
| [#43](https://github.com/rockstoneaidev/travel-companion/issues/43) | Self-hosted routing: OSRM/Valhalla cost lever *(cost-triggered)* | platform | — (Routing port exists) |
| [#44](https://github.com/rockstoneaidev/travel-companion/issues/44) | Interruption quality: metrics, guardrails & Phase 2 exit | platform | everything |
| [#45](https://github.com/rockstoneaidev/travel-companion/issues/45) | Opportunistic sources: BestTime, Flickr density, event APIs, pack mining *(stretch)* | backend | 39 |

### Critical path & parallel tracks (Phase 2)

```text
Gate:      #19 read (post France trip, ~mid-Aug) → everything below
Mobile:    #28 → #33 → #34 ─┬→ #36 · #37         (the new track; battery + permission UX
                            │                     are make-or-break, PRD risk 4)
Backend:   #29 → #30 → #31 ─┘                     (the interruption spine, PRD risk 5)
           #29 → #35 → #38
Privacy:   #32                                    (parallel from day 1; M4 cannot ship
                                                   without it — consent before first push)
Scouts:    #39 · #40                              (M5)
M6:        #41 · #42[gated] · #43[cost-triggered] · #44 → exit read
```

**The road-trip-grade cut line (proposed):** #28–#37 + #44's core dashboards + #38's stay-aware
horizon + #39's PracticalScout are **must**; the vibe axis, GTFS feeds, #40, #42 (gate may not
fire), #43 (trigger may not fire), #45, and Reverb foreground realtime are **stretch**.

### Decisions needed at the Phase 2 gate

1. **Mobile stack** — native Swift/Kotlin vs. RN + native background-geolocation SDK (#28's
   output; PRD §13.1 defers it to exactly this moment).
2. **Second launch region** — where #41 points (founder choice; no demand signal exists yet).
3. **Phase 2 exit criteria numbers** — the PRD gives the shape (§7.2 Q3, §7.3) but no targets.
   Proposed, following the PRD's own instrument-first pattern (§18.4): **(a)** push acceptance
   rate ≥ *target set after 2 weeks of instrumented Trip Mode data* with a floor discussion at
   ~15%; **(b)** Trip Mode disable/abandonment < 10% of users who enabled it; **(c)** battery
   complaint rate ≈ 0 in the pilot group; **(d)** a meaningful share of north-star moments
   originating proactively (push/geofence) rather than pull. *These are proposals, not PRD — they
   need a founder decision before M6 closes.*
4. **Embedding gate read** — have facet weights plateaued? (Decides whether #42 is real work or a
   recorded no-op.)
