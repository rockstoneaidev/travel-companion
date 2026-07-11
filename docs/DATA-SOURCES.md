# Travel Companion AI — World Model Data Sources

| | |
|---|---|
| **Document status** | Draft v1.0 |
| **Date** | 2026-07-10 |
| **Companion to** | [PRD.md](PRD.md) — see §9 (World model) and §14.3 (Cost model) |

---

## 1. Framing

The wrong question is *"Which Places API should I use?"* The right question is:

> **How do we build the best possible world model?**

No single source is good enough. Google is touristy and obvious; OSM has no stories; Wikipedia has no restaurants; events APIs miss the courtyard jazz trio. The value comes from **combining many sources with different strengths** — collected, normalized, deduplicated, and enriched by scouts into a unified opportunity graph that the agent reasons over. The AI never reasons directly from "the internet."

```text
                AI reasoning (stories, decisions)
                              |
                    Opportunity graph
                              |
                 Places + Events + Routes
                              |
   Open canonical core        |        Live enrichment edge
   (owned, persisted)         |        (fetched at recommendation time)
   OSM · Overture · Wikidata  |        Google Places details/hours
   Wikivoyage · Wikipedia     |        Weather · traffic · crowding
   Gov datasets · DATAtourisme|        Live events · alerts
   Curated layer · User data  |
                              |
                         Raw world
```

### 1.1 The licensing-first architecture rule (non-negotiable)

Sources divide into two classes, and confusing them creates legal debt that is expensive to unwind:

- **Canonical core** — open-licensed data we may persist, transform, and build our `places` database on: OSM (ODbL), Overture (CDLA-Permissive), Wikidata (CC0), Wikipedia/Wikivoyage (CC BY-SA), government open data (Licence Ouverte / Etalab, etc.), our curated layer, our users' contributions.
- **Enrichment edge** — proprietary APIs whose ToS prohibit building our own database from them: **Google Places data must never seed or persist into the canonical places table.** Store the `place_id` (permitted), fetch details/hours/photos live or within permitted short-term caching windows, discard. Same discipline for any commercial API.

Every source adapter in `SourceRegistry` carries license metadata: *what may be stored, for how long, with what attribution.* This is enforced in code, not in a wiki page.

**ODbL resolution:** the share-alike review has been done — see [ODBL-REVIEW.md](ODBL-REVIEW.md). Outcome: conflating OSM with other sources' POIs (our entity-resolution design) creates an ODbL Derivative Database, and publicly serving recommendations triggers the §4.6 obligation to offer that database. Adopted architecture ("open core, proprietary shell"): the conflated geo-core (names/geometry/categories from OSM + Overture + Wikidata + gov open data) is treated as ODbL and publishable; all proprietary value (curated layer, packs, user signals, scores) lives in independent data types linked by `place_id`, which the OSMF Collective Database Guideline keeps out of share-alike. Pending final counsel confirmation before public launch.

### 1.2 Source credibility tiers (feeds the confidence score)

```text
Tier A  Government registries, official tourism boards, transit operators, Met offices
Tier B  Wikidata/Wikipedia/Wikivoyage, established event APIs, Michelin-class guides
Tier C  OSM (great coverage, variable completeness), municipal calendars, local press
Tier D  Blogs, Reddit, YouTube, forums — hypothesis generators, never sole evidence
Own     Curated layer (Tier A after human review), user feedback (grows toward A)
```

Rule: a Tier-D claim ("locals say the back room is the real experience") can *boost* an opportunity but never *establish* facts (existence, hours, price). Operationally this means a **D-only** candidate is never served or digested — it is a *lead* for corroboration until a non-D source is found (the serve/hold gate in [SCORING.md](SCORING.md) §2.1). Cross-source agreement raises confidence; disagreement lowers it. The numeric tier values and the full `confidence` formula (agreement, freshness, coverage, and the Tier-D-only cap at 0.40) are defined in [SCORING.md](SCORING.md) §4.6.

---

## 2. Layer 1 — Global foundation (the canonical core)

> **These three sources also define the place taxonomy.** The **Type** axis (`PlaceType` /
> `PlaceTypeDomain`) is a normalisation of Overture categories, OSM primary tags, and Wikidata
> `instance-of` — assigned during entity resolution and stored alongside the raw source tags (which
> are never discarded). See [TAXONOMY.md](TAXONOMY.md) §3. Appeal facets are assigned from the
> evidence bundle on top of Type.

| Source | Strengths | Weaknesses | License / access | Phase |
|---|---|---|---|---|
| **OpenStreetMap** (via regional **Geofabrik extracts** → own PostGIS via osm2pgsql; public Overpass only for ad-hoc dev queries) | Viewpoints, ruins, fountains, monuments, waterfalls, shelters, caves, picnic sites, old city gates — things Google doesn't have. Community-driven, incredibly underrated. | Variable completeness, no narrative, tag inconsistency | ODbL (share-alike on derived DB — review once) | **P1** |
| **Overture Maps** places layer | Open, downloadable, global POI base (Meta/Microsoft/TomTom-backed); the best *legal* foundation for our canonical places table | Younger dataset; verify freshness per region | CDLA-Permissive 2.0 | **P1** |
| **Wikidata** (SPARQL) | Structured knowledge: "every medieval castle within 25 km," "all UNESCO sites," "everything by Gaudí." Also the relationship graph (§8) | Query complexity; sparse for mundane POIs | CC0 | **P1** |
| **Wikipedia** (geosearch + articles) | History, stories, famous people, architecture, legends — the narrative layer | Not for restaurants/practicalities | CC BY-SA (attribute) | **P1** |
| **Wikivoyage** | Structured travel content — see/do/eat/drink listings, district guides, written *for travelers*. Major omission from most source lists | Uneven depth by destination | CC BY-SA (attribute) | **P1** |
| **Foursquare OS Places** | ~100M open POIs, useful for cross-validation and category coverage | Freshness varies | Apache 2.0 | P1–P2 |
| **GeoNames** | Toponyms, admin regions, populated-place metadata | POI-thin | CC BY | P1 (utility) |
| **Wikimedia Commons** | Historical photos, landmark imagery | Licensing varies per file — check each | Mixed CC (per-file) | P2 |
| **Mapillary** | Street-level imagery; later: "what does this place actually look like" | Not an MVP need | CC BY-SA imagery, API ToS | P3 |
| **OpenTripMap** | Convenience aggregator over OSM+Wikidata | Adds little over doing it ourselves | Free tier | Optional |

### Layer 1b — Live enrichment edge (proprietary, never persisted)

| Source | Use for | Hard constraints |
|---|---|---|
| **Google Places API (New)** | Opening-hours verification, reviews-derived quality signal, photos, restaurant/café coverage — fetched at enrichment/recommendation time | **Do not build our database from it.** Store `place_id` only; respect current caching windows; significant per-call cost → budget via tile cache (PRD §14.3) |
| **Google Routes API** | Detour cost, route corridors, travel times | Pay-per-call; cache computed friction per (place, corridor) |
| Alternatives to keep warm: **OpenRouteService / self-hosted OSRM or Valhalla** | Same, on OSM data, no ToS entanglement | Self-hosting effort; P2 cost lever |

---

## 3. Layer 2 — Events (where recommendations become alive)

| Source | Notes | Phase |
|---|---|---|
| **Municipal & tourism-board event calendars** (visitparis, visitstockholm, town-hall pages — many expose RSS/iCal) | Food festivals, markets, exhibitions Google misses. High value, low glamour | **P1** |
| **OpenAgenda** | Open events platform, very strong in France — launch-region gold | **P1** |
| **DATAtourisme events feed** | See §7 — national French open feed includes events | **P1** |
| **Ticketmaster Discovery** | Mainstream concerts/shows; good API | P1–P2 |
| **Eventbrite** | Long-tail organized events; API access has tightened — verify current terms | P2 |
| **Bandsintown / Songkick** | Live music by location | P2 |
| **Local newspapers via RSS** (Nice-Matin, Le Bien Public, Le Dauphiné, …) | Street festivals, temporary exhibitions, closures, fireworks. Headlines/RSS often free; **full text frequently paywalled — respect it**; extract claims + citation, not article text | P1 pilot in launch region |
| ~~Facebook Events~~ | **Removed.** No meaningful third-party API access since 2018. Manual curation of a specific venue's public page is the only legitimate path | — |
| Meetup | API now paid/restricted; niche value for travelers | Skip |

---

## 4. Layer 3 — Tourism organizations & government data (gold, underused)

- **Regional/national tourism boards** (Burgundy Tourism, Visit Scotland, Visit Iceland…): itineraries, hidden villages, scenic drives, wine routes, seasonal highlights. Some have feeds/APIs; most become **inputs to the curated layer** (PRD §9.4) — scraped respectfully or ingested manually with permission. Partnership potential: tourism boards *want* distribution to exactly our users.
- **Government open data**: cultural-heritage registries, protected buildings, archaeological sites, public art, hiking networks. Often the highest-authority data available (Tier A) and openly licensed. France: see §7. UK: Historic England. Elsewhere: national heritage registries, national park services.
- **Public/school holiday calendars** (e.g., Nager.Date; French zone A/B/C vacation calendars): free, and a strong *crowding and opening-hours prior* the original list missed entirely.

---

## 5. Layer 4 — Specialist databases (per-interest scouts)

Reality check: most of the famous names here (**Vivino, AllTrails, Michelin, Gault&Millau, Atlas Obscura, PhotoHound, 500px**) have **no public API and restrictive terms**. They are inspiration, manual-curation references, or future partnerships — not adapters. What is actually usable:

| Domain | Usable now | Curation-only / partnership |
|---|---|---|
| Wine & food | Regional wine-route open data, farm/market associations, DATAtourisme categories, market-day registries | Michelin, Gault&Millau, Vivino |
| Architecture & history | UNESCO list (open), Wikidata, national monument registries (Base Mérimée), DOCOMOMO lists | — |
| Nature & outdoors | OSM hiking/trail data, Waymarked Trails, national park open data, IGN (France) | AllTrails, Komoot (API is partner-gated) |
| Photography | Flickr API geotagged-photo density (photogenic-spot + unusualness signal — one of the few *usable* social sources) | PhotoHound, 500px |
| Unusual places | Our UnusualnessScout computes it from signals (PRD §9.5) | Atlas Obscura (the benchmark to beat; don't scrape it) |
| Military/WWII | Wikidata, national memorial registries, museum open data | Battlefield databases |

Principle: **specialist depth comes primarily from computing signals over the open core + the curated layer**, not from hoping a niche API exists.

---

## 6. Layer 5 — Human knowledge (LLM-read sources)

The most exciting layer and the most legally/qualitatively delicate. Rules of engagement:

- **Store extracted claims with provenance, never bulk text.** Each claim: source URL, retrieved_at, excerpt (short quote), credibility tier D, language.
- Respect robots.txt, paywalls, and API terms. No "heavy web crawling" (PRD §8.4).
- Tier-D claims generate *hypotheses* that other evidence must corroborate before high-confidence surfacing.

| Source | Access reality | Use |
|---|---|---|
| **Blogs (travel/local/food/photo) via RSS** | Clean, legal, underrated | Primary Layer-5 pipeline: LLM extracts place-claims from feeds in the launch region |
| **Reddit** (r/france, r/paris, r/travel…) | API paid/limited since 2023 — budget it or use compliant data partners | "Most underrated place near Dijon" threads are genuinely rich; batch-mine per region during pack building (§8), not continuously |
| **YouTube** | Official caption/transcript access where offered; ToS-sensitive | "10 hidden villages in Provence" → extract candidate list during pack building |
| Podcasts / books / guidebooks | Copyright — no ingestion | Human curation inspiration only |

---

## 7. Launch-region spotlight: France (Burgundy/Provence)

If the launch region is French, the original source list missed the three best sources in the country:

| Source | What it is | Why it matters |
|---|---|---|
| **DATAtourisme** (datatourisme.fr) | **National open-data platform aggregating POIs and events from all regional/departmental tourism boards**, in a structured ontology, under open license (Etalab) | The single highest-value source for a French launch: exactly the tourism-board "gold" of Layer 3, already aggregated, already structured, legally ours to persist. Build a first-class adapter early |
| **POP / Base Mérimée** (pop.culture.gouv.fr) | Ministry of Culture open platform: every protected Monument Historique, plus Palissy (objects), Joconde (museum collections) | Authoritative, open-licensed historical/architecture layer — the "tiny chapel with the rare fresco" lives here |
| **OpenAgenda** | Open event data, dense in France | Local events beyond Ticketmaster's mainstream |
| Supporting cast | **Météo-France** open data · **SHOM** (tides) · **IGN** (open geodata, hiking) · **data.gouv.fr** (markets, heritage, trails) · local press RSS (Le Bien Public, JSL for Burgundy) · **Guide Michelin/Green Guide as curation reference only** | |

**Action:** launch-region choice (PRD §18.1) should weigh open-data richness. France's open tourism data is unusually good — a genuine argument for Burgundy/Provence over regions with closed data.

---

## 8. Regional Knowledge Packs (adopted, with one correction)

The strongest idea in the source discussion — adopted as a first-class concept, with one correction: **packs are not temporary and are never deleted.** A pack is expensive to build and valuable to every future traveler in the region. Packs are **persistent, shared, versioned, TTL-refreshed artifacts** — the natural extension of the shared tile-cache principle (PRD §9.3) and the industrialization of the curated layer (PRD §9.4).

**A Regional Knowledge Pack contains:**

```text
Identity        What the region is famous for; what locals are proud of.
Seasonal        Highlights by season; harvest/bloom/light calendars.
Food & drink    Signature dishes, producers, market days, wine routes.
Built heritage  Architectural styles, key monuments, hidden gems (Mérimée-backed).
Nature          Scenic routes, viewpoints, walks, weather-dependent experiences.
Now             Current events, temporary exhibitions, closures (short-TTL section).
Craft           Common tourist mistakes; local etiquette; timing wisdom
                ("the market is Tuesday; everything closes 12–14h").
Stories         Wikidata-anchored narrative threads (see below).
```

**Build pipeline (semi-automated):**

```text
1. Trigger      New region needed (launch decision, or P3: user heading somewhere new).
2. Research     LLM research pass over: Wikivoyage + Wikipedia + Wikidata + DATAtourisme
                + tourism boards + blog RSS + batch Reddit/YouTube mining (§6).
3. Ground       Every claim linked to canonical places (entity resolution, PRD §9.6)
                and evidence with credibility tiers. Ungroundable claims dropped.
4. Review       Human curation pass — mandatory for launch regions (P1),
                spot-check sampling later (P3).
5. Publish      Versioned pack (pack_version) into the world model; scouts and the
                ranking explanation layer consume it as context.
6. Refresh      Per-section TTLs: "Now" hourly-daily; seasonal quarterly;
                identity/heritage yearly.
```

This is how the companion feels like it has *lived* in Burgundy rather than queried an API — and it is also the Phase 3 regional-expansion playbook (PRD §8.3) made concrete: a new region = building and reviewing its pack.

**Stories via Wikidata, not a bespoke graph.** The "AI-generated knowledge graph" idea (castle → built by Philip the Bold → whose tomb is here → whose vineyards are nearby) is right about the *product value* — recommendations become stories — but wrong about the build: **Wikidata already is that graph.** Story threads are precomputed per pack by traversing Wikidata relations among the region's canonical places, verified against evidence, and stored as `story_threads` in the pack. Building a proprietary knowledge graph from scratch is a research project we defer indefinitely; *traversing* an existing one is a P1–P2 feature.

---

## 9. Context layers: weather, astronomy, transport, crowding

### Weather (use intelligently, not just display)

| Source | Notes |
|---|---|
| **Open-Meteo** | Free/cheap, no-key tiers, global — MVP default |
| **MET Norway** | Free with attribution, excellent Nordics |
| **Météo-France** | Launch-region authority; vigilance alerts |

Usage is *rescoring*, per PRD §9.2: cloudy → suppress viewpoints, boost museums; rain in 30 min → "the viewpoint, now"; snow → winter-hike category. Weather changes **rescore existing opportunities before fetching anything new.**

### Astronomy — compute, don't integrate

Sunrise, sunset, golden/blue hour, moonrise, moon phase are **deterministic math — use a library (suncalc/astral-class), zero API, zero cost, works offline.** This was over-engineered as an "integration" in the original list. Only genuinely external: aurora forecasts (NOAA SWPC, free) and **tides** (SHOM for France; WorldTides API elsewhere — paid) for coastal opportunities ("low tide → the tidal island is walkable"). Meteor showers: a static annual table. All P1-cheap, high "why now" value.

### Transportation

Google Routes (edge, §2) or self-hosted OSRM/Valhalla · national rail & public-transit GTFS feeds (widely open) · road-closure/traffic feeds (national road authorities; Bison Futé in France) · ferry schedules and mountain-pass status per region (pack "Now" section) · EV charging: OpenChargeMap (open). Purpose: **route corridors and honest detour costs** — "you'll naturally pass here" (P2).

### Crowding & social signals

Honest assessment of the original Layer 10:

| Signal | Verdict |
|---|---|
| Google "Popular Times" | **Not in the official API; scraping violates ToS. Do not build on it.** |
| BestTime.app | Legitimate commercial alternative for foot-traffic estimates — P2 evaluation |
| **Flickr geotagged density** | Usable API; photogenic-spot signal + *low-density-but-high-quality = unusualness* signal — P1–P2 |
| Instagram locations | No public API; scraping violates ToS — skip |
| Strava heatmap | Not licensed for third-party products — skip (GPX/segment data via proper API scopes only, P3 at best) |
| Cheap proxies | Holiday calendars (§4), event schedules, cruise-ship port calendars (coastal towns), market days — free and surprisingly predictive |

---

## 10. Layer 11 — Our own users (the compounding moat)

From day one, every accepted/visited/saved recommendation is a data point no competitor has. Lightweight structured feedback affordances:

```text
⭐ Unexpectedly amazing   ⭐ Overrated        ⭐ Worth the detour
⭐ Great with kids        ⭐ Great at sunset  ⭐ Local secret
```

These tags attach to canonical places with full provenance (which user archetype, which context — season, weather, time). Over years, this layer outgrows every external source in precision; it starts Tier-C per individual signal and becomes Tier-A in aggregate. Governance: it is *our* data under *our* privacy commitments (PRD §16) — user contributions must be covered by the consent flow, and "local secret" tags from identifiable users are never exposed with attribution.

---

## 11. What we will NOT use (explicit exclusions)

| Source/approach | Reason |
|---|---|
| Facebook Events API | Effectively closed since 2018 |
| Scraping Google Popular Times / Instagram / Atlas Obscura / AllTrails / Vivino | ToS violations; legal debt in the core asset |
| Strava heatmaps | Not licensed for this |
| Bulk ingestion of books/guidebooks/podcasts | Copyright |
| Heavy general web crawling | PRD §8.4 — bounded scouting only |
| Building our POI database from Google Places data | Direct ToS violation; the enrichment-edge rule (§1.1) exists to prevent exactly this |
| A bespoke AI knowledge graph | Traverse Wikidata instead (§8) |

---

## 12. Phased adapter roadmap

### Phase 1 (launch region, pull MVP)

```text
Canonical core:   OSM regional extract (own PostGIS) · Overture places ·
                  Wikidata · Wikipedia geosearch · Wikivoyage
France layer:     DATAtourisme · POP/Base Mérimée · OpenAgenda
Curated:          CuratedScout + Regional Knowledge Pack v1 (human-reviewed)
Events:           Municipal/tourism calendars (RSS) · Ticketmaster · local press RSS
Enrichment edge:  Google Places (hours/photos verification) · Google Routes
Context:          Open-Meteo · suncalc-class library · holiday calendars
Layer 5 pilot:    Blog RSS extraction for the launch region
```

### Phase 2 (proactive companion)

```text
NewsLocalScout at scale (closures, alerts) · BestTime evaluation · Flickr density
· GTFS/transit + road-closure feeds · tides (coastal regions) · aurora (Nordics)
· self-hosted routing (cost lever) · Eventbrite/Bandsintown · batch Reddit/YouTube
  mining into pack builds
```

### Phase 3 (expansion)

```text
Pack pipeline as the per-region playbook · tourism-board partnerships
· Mapillary imagery understanding · specialist partnerships (Komoot, Michelin-class)
· user-data layer reaches Tier-A aggregate authority
```

---

## 13. Summary of changes vs. the original source discussion

| Original | Disposition |
|---|---|
| 12-layer framework, "combine many sources" | **Adopted** — with licensing classes, credibility tiers, and phasing added |
| Regional Knowledge Packs ("temporary, then deleted") | **Adopted as flagship — but persistent, shared, versioned, TTL-refreshed** |
| Regional/local AI scouts | Adopted as the pack build pipeline (§8), not free-running agents |
| AI-generated knowledge graph | **Replaced** by Wikidata traversal → story threads |
| Google Places as a foundation layer | **Demoted to enrichment edge** — ToS forbids database-building |
| Facebook Events, Instagram, Strava heatmap, Popular Times scraping | **Removed** — inaccessible or ToS-violating |
| Missing entirely | **Added:** Overture Maps, Foursquare OS Places, Wikivoyage, DATAtourisme, POP/Mérimée, OpenAgenda, Open-Meteo, holiday calendars, GTFS, OpenChargeMap, aurora/tide sources, Geofabrik-extract strategy, astronomy-as-a-library |
| No prioritization | **Added:** P1/P2/P3 adapter roadmap tied to PRD phases |
| No compliance model | **Added:** canonical-core vs. enrichment-edge rule, license metadata in SourceRegistry, claim-with-provenance storage |
