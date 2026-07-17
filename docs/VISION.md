# VISION — direction beyond Phase 1

**Status:** direction, not specification. Nothing in this document overrides the PRD's phasing
(PRD §8, CLAUDE.md non-negotiable 5) or creates Phase-1 scope. It exists so that decisions with
long shadows — especially the ones that are cheap now and impossible later — are made on the
record, and so that nobody mistakes launch-phase machinery for the scaling plan.

Recorded 2026-07-13. One piece of this document is already implemented (§2, because it could not
wait); everything else waits for its phase.

---

## 1. Trip-plan-driven ingestion — how "anywhere in the world" actually works

`IngestRegion` (`app/Domain/Sources/Data/IngestRegion.php`) is the **launch catalog**: a
hand-reviewed list of coarse, city-scale regions (Stockholm + the France corridor), bulk-ingested
ahead of demand. It is deliberately code-reviewed reference data, and it stays that way. It is
*not* the global story, and there will never be an entry per village.

The global story has two halves, both already in the architecture:

1. **Tile scouting (PRD §9.2/§9.3)** is the fine-grained, inherently global path: when a user is
   somewhere, scouts fetch the current H3 tile + neighbors on demand, cached and shared across all
   users. It needs no region catalog at all.
2. **The trip plan is the demand signal that turns on-demand into ahead-of-demand.** When a user
   enters a trip with places and dates (PRD §9.2 "when trip starts", §10 pre-scouting), the system
   derives a bounding box per planned stop — geocoded city or admin boundary — and feeds it through
   the *same* box pipeline `IngestRegion.boxes()` already uses. A trip entered two weeks out gives
   the ingest pipeline exactly what it wants: lead time. Overpass politeness, entity resolution,
   enrichment, even LLM pack drafting stop being latency problems when there are days instead of
   seconds. Only the hardcoded `all()` list is launch-scoped; the machinery underneath is already
   arbitrary-bbox.

Second-order effect worth building when the time comes: **trip plans prioritize the curation
queue.** "Which regions have the most booked user-days in the next 30 days" is the signal for
where human pack-review effort goes — the densification playbook (PRD §9.4) made demand-driven.

Where there is no curated density, the product says so honestly: "we don't know this area deeply
yet" (PRD §8.2). Auto-ingested OSM alone is not the product; pretending otherwise is PRD risk #1.

## 2. The opportunity archive — history is written nightly or never

**Decided 2026-07-13, implemented immediately** — the one piece of this vision that could not
wait, because it is retention-shaped: an expired opportunity that is deleted tonight cannot be
recovered in three years, and the France trip is the first data worth keeping.

An opportunity that expired is a moment that *happened*. The nightly reaper
(`ReapExpiredOpportunitiesJob` → `Domain/Opportunities/Actions/ReapExpiredOpportunities`)
therefore **archives before it deletes**:

- Expired **time-bound** opportunities (event / ephemeral / seasonal) move their
  **license-storable subset** into `archived_opportunities` + `archived_opportunity_evidence` —
  place-keyed, tile-keyed, dated, no personal data (it is the shared tile world being archived,
  never user activity; nothing here belongs in ROPA).
- **Evergreen** materializations are reaped without archiving — the place itself is permanent in
  `places_core`; a daily "this park still exists" row is not history.
- Evidence is archived per row and **only from sources whose `SourceDescriptor` grants
  `archivable`** (DATA-SOURCES §1.1): indefinite retention is a narrower right than TTL'd storage.
  Google-derived anything is edge-only and never gets this far; event APIs and news sources
  typically allow caching but forbid retention — for them the archive keeps the *fact* an
  opportunity existed (our own title/summary, the window, the place), not the source's content.
- **Nothing reads the archive.** It is an append-only record for §3; serving decisions must never
  consult it. That is a design property, not a temporary state.

What accumulates is a temporal log — *what was going on in this area, then* — which is unique
content that nobody, including us, can ever recreate retroactively. It compounds from day one at
the cost of a few text rows per day.

## 3. The content surface — a public site on top of the archive and the packs

**Phase 3 at the earliest. Explicitly out of scope until the app has met its Phase-1/2 exit
criteria (PRD §8); monetization is out of the PRD's scope entirely (PRD open question 6).**

The thesis: a public, SEO-facing website of regions/cities/towns, funded by traffic and ads and
acting as an acquisition funnel for the app. Recorded here with its honest shape:

- **What is genuinely valuable:** the temporal archive (§2) — "what was happening in Dijon in July
  2026" is content no competitor will ever have — and the curated long-tail layer. Both are
  unique, compounding assets.
- **What is explicitly not the plan:** programmatic thin pages ("every village, auto-summarized
  OSM") — that is exactly what search engines demote and AI overviews strip-mine. Pages exist
  where the unique assets have something to say.
- **The moat tension, unresolved on purpose:** the app's moat is the proprietary content layer
  (PRD §6); a free site publishes part of it to competitors and scrapers. What goes public
  (breadth, the archive, teasers) versus what stays in-app (the personalized, contextual,
  real-time layer — unscrapeable by construction) is a product decision to make *then*, not a
  default to drift into.
- **Licensing goes public-facing:** pages built on the geo-core make the ODbL share-alike and
  attribution obligations outward-facing (anticipated — ODBL-REVIEW treats the core as
  publishable), and every archived evidence row already carries its license and attribution for
  exactly this moment.
- **For counsel, alongside the ODbL confirmation (ODBL-REVIEW §9):** the fact-vs-provenance
  question for the archive once it feeds a public site. Working position (2026-07-13): "facts
  aren't copyrightable" does not clear retention — API ToS bind contractually regardless of
  copyright, and the EU sui generis database right (96/9/EC) protects systematic extraction of
  facts as such (the same right that makes ODbL enforceable for us). A fact is archivable when it
  arrived through an unencumbered channel; restricted sources are *leads*, and the archive stores
  the corroborating open provenance, never the restricted feed's data. Confirm this reading
  before the archive is published anywhere.

## 4. What this changes today

The `archivable` descriptor flag, the two archive tables, and the nightly reaper (§2).

**Since first draft, §1 has partly shipped.** Dynamic region derivation is real (E48):
`DeriveRegionForPosition` snaps any pin to an H3 res-5 cell and `LearnAreaIfUnknown` learns it,
deduped and rate-limited — *on arrival* (`LearnAreaOnSessionStart` / `LearnAreaOnPositionMoved`).
What is still missing, and now specced in **`docs/PLAN-DRIVEN-INGESTION.md`**, is the plan-time
front-end: a **global gazetteer** so you can *search* a place before you go, a **trip-plan
trigger** that runs ingestion ahead of arrival on the lead time, and a **cheap-first** phase
split so speculative plans warm the geo-core without paying for evidence/photos they may never
need. Still not built, and out of scope here: the public content site (§3).
