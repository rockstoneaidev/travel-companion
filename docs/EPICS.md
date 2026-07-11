# Phase 1 Epics — Index

| | |
|---|---|
| **Status** | Live — the work plan is on GitHub; this file is the map |
| **Created** | 2026-07-12 · issue numbers match epic codes (E1 = #1 … E19 = #19) |
| **Milestones** | [M1 — Stockholm walking skeleton](https://github.com/rockstoneaidev/travel-companion/milestone/1) (due Jul 18) · [M2 — France-ready](https://github.com/rockstoneaidev/travel-companion/milestone/2) (freeze Jul 25; trip Jul 27–Aug 7) · [M3 — Phase 1 complete](https://github.com/rockstoneaidev/travel-companion/milestone/3) |

Working agreement: pick up an epic by assigning yourself / commenting on the issue; reference
`E<n>`/`#<n>` in commits; an epic's **Done when** is its acceptance test; specs in `docs/` remain
authoritative — epics carry scope, not design.

## M1 — Stockholm walking skeleton (thin end-to-end slice on a phone in Liljeholmen)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#1](https://github.com/rockstoneaidev/travel-companion/issues/1) | World-model foundation: places schema, taxonomy enums, ODbL boundary | backend | — |
| [#2](https://github.com/rockstoneaidev/travel-companion/issues/2) | Stockholm ingest: Overture + OSM + Wikidata adapters | backend | 1 |
| [#3](https://github.com/rockstoneaidev/travel-companion/issues/3) | Entity resolution v1 | backend | 1, 2 |
| [#4](https://github.com/rockstoneaidev/travel-companion/issues/4) | Trips & Explore Sessions: domain + API | backend | 1 |
| [#5](https://github.com/rockstoneaidev/travel-companion/issues/5) | Tile cache, scouts & mode-aware coverage | backend | 1, 2 |
| [#6](https://github.com/rockstoneaidev/travel-companion/issues/6) | Reachability gate & travel-time estimator | backend | 1, 4 |
| [#7](https://github.com/rockstoneaidev/travel-companion/issues/7) | Scoring, feed selection & taste learner | backend | 1, 5, 6 |
| [#8](https://github.com/rockstoneaidev/travel-companion/issues/8) | Passo UI foundation & PWA shell | frontend | — (parallel from day 1) |
| [#9](https://github.com/rockstoneaidev/travel-companion/issues/9) | Core screens: session start, feed, detail, empty | frontend | 4, 7, 8 |
| [#10](https://github.com/rockstoneaidev/travel-companion/issues/10) | Replayer, decision traces & cost instrumentation | platform | 7 |
| [#11](https://github.com/rockstoneaidev/travel-companion/issues/11) | Curation pipeline & the stockholm-test pack | content | 1, 3 |

## M2 — France-ready (in hand in Paris)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#12](https://github.com/rockstoneaidev/travel-companion/issues/12) | Gemini agent layer: evidence-grounded voice | backend | 7, 10 |
| [#13](https://github.com/rockstoneaidev/travel-companion/issues/13) | France source adapters & corridor ingest | backend | 2, 3, 5 |
| [#14](https://github.com/rockstoneaidev/travel-companion/issues/14) | France city packs (content — founder review hours) | content | 11, 12, 13 |
| [#15](https://github.com/rockstoneaidev/travel-companion/issues/15) | M2 screens: map, kept, calibration, offline hardening | frontend | 8, 9 |
| [#16](https://github.com/rockstoneaidev/travel-companion/issues/16) | Edge enrichment & context: Google verify, weather, astronomy | backend | 5, 7 |
| [#17](https://github.com/rockstoneaidev/travel-companion/issues/17) | Privacy plumbing: retention, home zone, deletion, export | platform | 4 |
| [#18](https://github.com/rockstoneaidev/travel-companion/issues/18) | Digest & Journal *(stretch)* | frontend | 7, 15, 16 |

## M3 — Phase 1 complete (post-trip)

| # | Epic | Track | Depends on |
|---|---|---|---|
| [#19](https://github.com/rockstoneaidev/travel-companion/issues/19) | Pilot expansion & exit-criteria instrumentation | platform | everything |

## Critical path & parallel tracks

```text
Backend spine:  #1 → #2 → #3 ─┬→ #5 → #7 → #12
                #1 → #4 → #6 ─┘        ↘ #10
Frontend:       #8 → #9 → #15 (+#18)      (parallel from day 1; mock data until #7)
Content:        #11 → #14                  (founder review-hours — the scarcest resource;
                                            start harvesting week 1)
France:         #13 → #14 · #16 · #17      (M2)
```

The France-ready cut line (decided): NOW + session start + detail + KEPT + offline + calibration +
7 packs + French sources + Gemini voice + MAP are **must**; digest/journal/admin dashboards are
stretch. API keys (Gemini, Google Maps Platform) are already in hand — no epic 0.
