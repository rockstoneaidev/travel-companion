# ODbL Share-Alike Review — Canonical Places Database

| | |
|---|---|
| **Document status** | Engineering legal analysis v1.0 — **not legal advice** |
| **Date** | 2026-07-10 |
| **Scope** | ODbL 1.0 implications of building the canonical places database (PRD §9.6, DATA-SOURCES.md §1.1) on OpenStreetMap data |
| **Action required** | Adopt the recommended architecture (§6, Option A); have licensed counsel confirm §9 before public launch |

> **Disclaimer.** This is a structured analysis by an engineering team against the license text and the OSMF's board-approved community guidelines. It exists to make the conversation with real counsel short and cheap, and to prevent the architecture from accruing legal debt in the meantime. It is not a substitute for legal advice, particularly regarding the EU Database Directive's sui generis right.

---

## 1. Executive summary

1. **Our planned entity-resolution design, as written in PRD §9.6, creates an ODbL Derivative Database.** Conflating OSM POIs with other sources' POIs and deduplicating is the *explicitly prohibited example* in the OSMF Collective Database Guideline ("combining a proprietary restaurant list with OSM data while removing duplicates creates a Derivative Database").
2. **The internal-use exception does not protect us.** ODbL §4.5(c) exempts internal use, but our app publicly serves recommendations, which are "Produced Works" queried from that database. ODbL §4.6 then requires us to **offer the entire Derivative Database (or an alterations file) to anyone**, and §4.4 requires that database to be licensed ODbL.
3. **This is survivable — cheaply — if we design for it now.** The share-alike obligation attaches to the *conflated geo-layer*, not to independent data types we keep alongside it. Per the Collective Database Guideline, our curated narratives, Regional Knowledge Packs, user signals, scores, and opportunities remain proprietary as long as they are separate data types sourced entirely from non-OSM origins, even when linked to OSM-derived places by reference.
4. **Recommendation (Option A): embrace it.** Treat the conflated canonical geo-core (names, locations, geometry, categories from OSM + Overture + Wikidata + government open data) as an ODbL database and be prepared to publish it. It is derived almost entirely from open sources — publishing it costs us nothing competitively (the moat is the curated layer, packs, and user signals, which stay proprietary) and is a positive community/marketing story. The alternative (Option B, strict source segregation) is documented in §7 but costs data quality.
5. **Hard rule either way:** Google Places data must never touch the ODbL layer — it is both a Google ToS violation and impossible to relicense under ODbL. This independently reconfirms the "enrichment edge" rule in DATA-SOURCES.md §1.1.

---

## 2. ODbL mechanics that matter to us

From the [ODbL 1.0 text](https://opendatacommons.org/licenses/odbl/1-0/):

| Concept | Definition (condensed) | Relevance |
|---|---|---|
| **Derivative Database** | A database *based upon* the licensed database: translation, adaptation, arrangement, modification, or extraction/re-utilisation of a **Substantial** part | Our PostGIS import of a Geofabrik regional extract is one; so is any conflated table containing OSM-derived rows |
| **Collective Database** | The database *in unmodified form* as part of a collection of **independent** databases | The escape hatch: independent layers alongside the OSM-derived layer are not share-alike |
| **Produced Work** | A work (image, text, sounds…) resulting from querying the database or a derivative | Every recommendation card, notification, and map view we serve |
| **Public Use** | Making available to persons outside your organisation/control | Serving app users = public use |
| **§4.4 share-alike** | Publicly used Derivative Databases must be licensed ODbL (or compatible) | Applies to the conflated layer |
| **§4.5(c) internal use** | Internal use of a Derivative Database is not public and does not trigger §4.4 | **Does not help us** — see §4.6 |
| **§4.6 offer obligation** | If you publicly use a Derivative Database **or a Produced Work from one**, you must offer recipients the entire Derivative Database or a machine-readable alterations file, free or at reproduction cost | The operative clause for our product: public recommendations → must offer the conflated layer |
| **§4.3 Produced Works** | Publicly used Produced Works require a notice that content was obtained from the Database, citing ODbL — but Produced Works themselves are **not** share-alike | Recommendations need attribution, not open-licensing |

**Substantiality:** per the OSMF [Substantial Guideline](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Substantial_-_Guideline), <100 features or areas up to ~1,000 inhabitants are insubstantial, and only if one-off and non-systematic; repeated small extractions accumulate. Our use — systematic extraction of all POI-relevant features across an entire launch region — is unambiguously **substantial**. There is no de-minimis escape for us, and we should not architect as if there were.

---

## 3. The controlling guideline: Collective Database

The board-approved [Collective Database Guideline](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Collective_Database_Guideline_Guideline) defines when combined OSM/non-OSM data stays independent (→ Collective, no share-alike crossover) versus becoming a Derivative Database:

**The test:** within a given regional cut, each *data type* must be sourced entirely from OSM or entirely from non-OSM. Homogeneous per type per region = independent.

**Allowed (independent, stays proprietary):**
- Adding our own attributes to OSM features — e.g., our curated story, credibility score, or user "worth the detour" tags linked to an OSM restaurant, where all of that attribute data comes from non-OSM sources. This is the guideline's own POI-enrichment example.
- Replacing/adding whole attribute types (URLs, opening hours) per region from entirely non-OSM sources.

**Prohibited (Derivative Database, share-alike):**
- **"Combining a proprietary restaurant list with OSM data while removing duplicates."** This is verbatim our entity-resolution/dedup design.
- Mixed sourcing of the same data type within one region (some POIs from OSM, some from Overture, merged into one canonical set).

**Also noted by the guideline:** database joins and functionally equivalent linkage *count as references* between datasets — references are fine in a Collective Database (the enrichment example uses them); it is the *merging of same-type content* that converts the whole into a Derivative Database.

Related approved guidelines we rely on: **Regional Cuts** (different sources for different regions is fine), **Horizontal Layers** (OSM basemap + proprietary overlay in the app UI is fine), **Trivial Transformations**, **Produced Work**, and **Geocoding** (§5).

---

## 4. Classification of our planned usage patterns

| # | Pattern (from PRD / DATA-SOURCES) | Classification | Obligation |
|---|---|---|---|
| 1 | Import Geofabrik regional extract into our PostGIS | Derivative Database (substantial) | None while internal-only; becomes relevant via #3 |
| 2 | Conflate OSM + Overture + Wikidata + DATAtourisme POIs into canonical `places` with dedup (PRD §9.6) | **Derivative Database** — the prohibited blending example | §4.4: layer must be ODbL; §4.6: must offer it publicly once recommendations ship |
| 3 | Serve recommendations/feeds/notifications generated by querying #2 | Public Use of **Produced Works** | §4.3 attribution notice in-app; triggers §4.6 for #2 |
| 4 | Curated layer, Regional Knowledge Packs, story threads, evidence bundles | Independent data types (all non-OSM-sourced), linked by reference | **None** — remains proprietary (Collective Database) |
| 5 | User feedback tags, profile signals, scores, opportunities, notification traces | Independent data types, our own origin | **None** — remains proprietary |
| 6 | Cross-source ID concordance (our `place_id` ↔ `osm_id`/`wikidata_qid`/`gers_id`) | Reference/metadata layer; likely independent, but systematic region-wide OSM-ID coverage is a gray zone | Low risk; confirm with counsel (§9.2) |
| 7 | Reverse-geocoding context events (e.g., Nominatim) | Per the [Geocoding Guideline](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Geocoding_-_Guideline): insubstantial extracts, **not** derivative/produced works | No share-alike on our context data; attribution required; must not aggregate geocodes into a general-purpose geodatabase |
| 8 | Embeddings computed over text/names that include OSM-derived content (PRD §10 step 5) | No approved OSMF guideline; arguably derived | Low practical risk; keep regenerable and segregated by source; flag to counsel (§9.3) |
| 9 | Wikipedia/Wikivoyage excerpts stored as evidence (CC BY-SA) | Separate evidence store = collective component | Keep out of the ODbL layer (CC BY-SA ↛ ODbL relicensing); attribute per CC BY-SA |
| 10 | Google Places data | Never enters any persisted layer | Google ToS + ODbL-incompatibility both forbid it |

---

## 5. The trap we are explicitly avoiding

A naive reading would be: *"share-alike only applies if we distribute the database, and we only serve an app."* Wrong for us, in two steps:

1. §4.5(c)'s internal-use exception covers the Derivative Database itself, **but** §4.6 is triggered by publicly using *"a Derivative Database **or a Produced Work from a Derivative Database**."* Our recommendation cards are Produced Works from the conflated layer. The moment the app has outside users, we owe the offer.
2. If, at that moment, the conflated layer contains anything that *cannot* be licensed ODbL (Google data, license-incompatible excerpts) or anything we *refuse* to publish (curated narratives blended into the same table), we are in breach with no clean remediation short of re-architecting the core database in production.

Hence: design the schema boundary now, while the database is empty.

---

## 6. Recommended architecture — Option A: "Open core, proprietary shell"

**Decision: treat the conflated geo-core as an ODbL database from day one, and be prepared to publish it.**

```text
┌────────────────────────────────────────────────────────────────┐
│ COLLECTIVE DATABASE (our system)                               │
│                                                                │
│  ┌──────────────────────────────┐   linked by our place_id     │
│  │ GEO-CORE  (ODbL, publishable)│◄──────────────┐              │
│  │ places_core:                 │               │              │
│  │  name, alt_names, geometry,  │   ┌───────────┴────────────┐ │
│  │  category, ext-ID concordance│   │ PROPRIETARY LAYERS     │ │
│  │ Sources: OSM ✚ Overture ✚    │   │ (independent data      │ │
│  │  Wikidata ✚ gov open data    │   │  types, never OSM-     │ │
│  │ (conflated + deduped — the   │   │  sourced):             │ │
│  │  Derivative Database)        │   │  curated_items, packs, │ │
│  └──────────────────────────────┘   │  story_threads, scores,│ │
│                                     │  opportunities, user   │ │
│  ┌──────────────────────────────┐   │  signals, profiles,    │ │
│  │ EVIDENCE STORE (per-source   │   │  embeddings, traces    │ │
│  │  licenses: CC BY-SA excerpts │   └────────────────────────┘ │
│  │  etc. — never merged into    │                              │
│  │  geo-core)                   │   Google Places: NOT persisted│
│  └──────────────────────────────┘   anywhere (edge-only)       │
└────────────────────────────────────────────────────────────────┘
```

**Why this is the right trade:**

- The geo-core is conflated from open sources; publishing it gives away nothing a competitor couldn't assemble from the same sources. **The moat (PRD §3) — curated layer, packs, user feedback, learned profiles, scoring — is untouched** and remains proprietary under the Collective Database Guideline's own enrichment example.
- Input licenses are compatible with ODbL output: OSM (ODbL), Overture Places (CDLA-Permissive 2.0 — attribution, permissive relicensing), Wikidata (CC0), French government data (Licence Ouverte 2.0 — attribution). Nothing in the geo-core resists ODbL.
- Cross-source agreement — our confidence signal (DATA-SOURCES §1.2) — keeps working, because conflation is *allowed* inside the ODbL layer.
- "We publish our conflated European places layer back to the commons" is a credible OSM-community and press story, and keeps us welcome users of the ecosystem we depend on.

**Engineering rules (enforced in schema and code review, not in a wiki):**

1. `places_core` may contain **only** data from ODbL-compatible open sources. A `source` enum on every attribute; CI check that no edge-source (Google, scraped, curated) values land there.
2. All proprietary value lives in separate tables keyed by `place_id`, each with a single non-OSM source family per data type per region.
3. Opening hours policy: hours verified via Google at recommendation time are served from the edge cache, never written into `places_core` (Google ToS + ODbL incompatibility). Hours from OSM/DATAtourisme may live in the core.
4. Evidence excerpts (CC BY-SA and others) live in the evidence store with per-row license metadata; they are content, not core attributes.
5. **Publication mechanism ready before launch:** a periodic machine-readable dump of `places_core` (or an alterations file vs. the source extracts, which §4.6(b) permits) behind a public URL, plus the ODbL license notice. Cost: one scheduled job.
6. **In-app attribution (Produced Works, §4.3):** an attribution/licenses screen citing "© OpenStreetMap contributors, ODbL", Overture Maps attribution, Etalab/Licence Ouverte attribution, and per-source evidence citations we already show for trust reasons (PRD §16). Map tiles shown in the UI carry their own attribution line.
7. Geocoding: per-result use is fine; never accumulate reverse-geocode results into anything resembling a general-purpose geodatabase; attribute the geocoder.

---

## 7. Option B (documented, not recommended): strict source segregation

Keep OSM out of the conflated core entirely: conflate only permissive/CC0 sources (Overture + Wikidata + government data) → that core stays proprietary; use OSM only as **whole data types per region** (e.g., viewpoints, trails, ruins, fountains sourced 100% from OSM in the launch region), linked by reference but never merged.

- *Pro:* no publication obligation at all; the pure-OSM layers' §4.6 offer is trivially satisfied by pointing at the source extract.
- *Con:* forfeits cross-source dedup and agreement for exactly the long-tail nature/heritage features where OSM is strongest (DATA-SOURCES §2); requires permanent per-data-type source discipline that will erode under product pressure; one accidental merge silently converts the proprietary core into a Derivative Database — the failure mode is invisible and cumulative.
- Choose B only if counsel or business development identifies a concrete harm in publishing the geo-core. The schema rules in §6 make a later A→B or B→A migration tractable either way.

---

## 8. Residual risk assessment

| Risk | Severity | Posture |
|---|---|---|
| §4.6 offer never set up before launch | High (clear breach once public) | Closed by §6 rule 5 — build the dump job in Phase 1 |
| Proprietary data accidentally written into `places_core` | Medium | Closed by schema separation + source enum + CI check |
| Google data leaking into persisted layers | High (dual violation) | Closed by edge-only rule; audit quarterly |
| Concordance table deemed derivative | Low | Even if so, it is publishable without competitive harm; confirm with counsel |
| Embeddings deemed derivative | Low | Regenerable artifact; segregate by source; confirm with counsel |
| EU sui generis database right nuances (we are EU-based, extraction happens in EU) | Medium | Counsel question — the ODbL is designed around it, but our jurisdiction makes it non-hypothetical |
| OSMF/LWG enforcement | — | Enforcement is historically dialog-first, but we do not lean on that: compliance is cheap here, and the OSM ecosystem is a dependency we want to be good citizens of |

---

## 9. Questions for licensed counsel (the short list)

1. **Confirm Option A's boundary:** does the schema in §6 (ODbL geo-core + independent proprietary layers linked by `place_id`) hold up as a Collective Database under ODbL §1 and the OSMF Collective Database Guideline, given EU sui generis database rights?
2. **Concordance table:** is a region-complete mapping of our `place_id` ↔ OSM IDs itself a substantial extraction requiring ODbL treatment? (We can publish it if yes; we want to know.)
3. **Embeddings and derived ML artifacts** computed over mixed-source text that includes OSM-derived names/tags: derivative or not; any publication exposure?
4. **CC BY-SA evidence excerpts** (Wikipedia/Wikivoyage) stored alongside and displayed in recommendations: confirm the evidence-store treatment and attribution format suffice.
5. **§4.6 satisfaction:** does a periodic public dump (or alterations file vs. named Geofabrik extracts) at a stable URL satisfy the offer obligation for an app with no other distribution?
6. Any French-law specifics for reuse of Licence Ouverte data combined into an ODbL database.

---

## 10. Decisions taken (pending counsel confirmation)

- **Option A adopted** as the working architecture; PRD §9.6 and DATA-SOURCES §1.1 updated to reference this document.
- `places_core` schema separation, source enums, and the publication job are Phase 1 engineering requirements, not post-launch cleanup.
- In-app attribution screen is a Phase 1 requirement (also serves PRD §16 source-transparency goals).
- Counsel review scheduled before public availability of the app (not before internal pilots — §4.5(c) covers genuinely internal use).

---

## Sources

- [ODbL 1.0 full text — Open Data Commons](https://opendatacommons.org/licenses/odbl/1-0/)
- [OSMF Community Guidelines index](https://osmfoundation.org/wiki/Licence/Community_Guidelines)
- [Collective Database Guideline (OSMF, board-approved 2016)](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Collective_Database_Guideline_Guideline)
- [Substantial Guideline (OSMF)](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Substantial_-_Guideline)
- [Geocoding Guideline (OSMF)](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Geocoding_-_Guideline)
- [OSMF Licence and Legal FAQ](https://osmfoundation.org/wiki/Licence/Licence_and_Legal_FAQ)
- [Overture Maps — Attribution and Licensing](https://docs.overturemaps.org/attribution/) (Places theme: CDLA-Permissive 2.0; transportation/OSM-derived themes: ODbL)
