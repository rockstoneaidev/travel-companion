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

**Archivability is a fourth dimension, distinct from TTL.** A source's terms can allow TTL'd storage yet forbid keeping the data forever (typical of commercial event APIs); open licenses generally allow both. Each `SourceDescriptor` therefore carries an explicit `archivable` flag — *may this data be kept indefinitely* — which the nightly opportunity reaper checks per evidence row before moving anything into the archive ([VISION.md](VISION.md) §2). `EdgeOnly` sources are never archivable; beyond that, only the source's actual terms decide, and the safe default is `false`. When adding a source, classify archivability at the same moment you classify `StoragePolicy`.

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
| **OpenStreetMap** (bounded regions via **Overpass**; regional **Geofabrik extracts** → own PostGIS via osm2pgsql once a region outgrows it — see note below) | Viewpoints, ruins, fountains, monuments, waterfalls, shelters, caves, picnic sites, old city gates — things Google doesn't have. Community-driven, incredibly underrated. | Variable completeness, no narrative, tag inconsistency | ODbL (share-alike on derived DB — review once) | **P1** |
| **Overture Maps** places layer | Open, downloadable, global POI base (Meta/Microsoft/TomTom-backed); the best *legal* foundation for our canonical places table | Younger dataset; verify freshness per region | CDLA-Permissive 2.0 | **P1** |
| **Wikidata** (SPARQL) | Structured knowledge: "every medieval castle within 25 km," "all UNESCO sites," "everything by Gaudí." Also the relationship graph (§8) | Query complexity; sparse for mundane POIs | CC0 | **P1** |
| **Wikipedia** (geosearch + articles) | History, stories, famous people, architecture, legends — the narrative layer | Not for restaurants/practicalities | CC BY-SA (attribute) | **P1** |
| **Wikivoyage** | Structured travel content — see/do/eat/drink listings, district guides, written *for travelers*. Major omission from most source lists | Uneven depth by destination | CC BY-SA (attribute) | **P1** |
| **Foursquare OS Places** | ~100M open POIs, useful for cross-validation and category coverage | Freshness varies | Apache 2.0 | P1–P2 |
| **GeoNames** | Toponyms, admin regions, populated-place metadata | POI-thin | CC BY | P1 (utility) |
| **Wikimedia Commons** | Historical photos, landmark imagery | Licensing varies per file — check each | Mixed CC (per-file) | P2 |
| **Mapillary** | Street-level imagery; later: "what does this place actually look like" | Not an MVP need | CC BY-SA imagery, API ToS | P3 |
| **OpenTripMap** | Convenience aggregator over OSM+Wikidata | Adds little over doing it ourselves | Free tier | Optional |

> **Note — the OSM fetch path (decided 2026-07-14).** Phase 1 ingests bounded city-scale regions
> (`IngestRegion`: Stockholm test region, then the France corridor), and a bbox that small is well
> within what public Overpass is meant to serve — the Stockholm region is four quadrant queries. So
> `OsmAdapter::search()` uses Overpass, and that is a sanctioned production path *at this scale*, not
> a shortcut. The Geofabrik → osm2pgsql import remains the correct path the moment a region stops
> being city-scale (a country, or continuous re-ingest); it is tracked on **E13**. Both paths feed
> the same pure `normalize()`, so swapping them touches `search()` only.

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

**Resolved (PRD §8.0, 2026-07-11):** the pilot is the founders' France trip corridor (Paris, Nantes,
Bordeaux, Toulouse, Nice, Lyon, Dijon/Burgundy — Jul 27–Aug 7 2026), so the French sources above are
confirmed P1 adapters. Pack plan: [CURATION.md](CURATION.md) §4.

### 7.1 Test-region addendum: Stockholm

Development and daily testing run from Stockholm (Liljeholmen base) before the trip. Sweden needs no
new adapter *class* — the global open core (OSM is excellent in Sweden, Overture, Wikidata,
Wikivoyage) carries it — plus, worth wiring where cheap:

| Source | Notes |
|---|---|
| **visitstockholm.se events** | Municipal event calendar (Layer 2 pattern) |
| **K-samsök / Riksantikvarieämbetet** | Swedish national heritage aggregator, open API — the Swedish counterpart to Mérimée |
| **Stockholms stad open data** | Parks, baths, public art |
| **SMHI open data** | Swedish met authority (weather layer, free API) |

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

**E45 / Flickr density — BLOCKED on a paid subscription (2026-07-15).** Flickr photo-density
was the intended source for the "cool"/appeal signal (feeding `unusualness`, never
credibility — a place with one Commons image is a coverage artifact, but a place hundreds of
people independently photographed is a real signal). It is **not buildable right now**:
Flickr has disabled API-key creation for free accounts — *"API key creation is available to
all Flickr PRO subscribers"* — so the source is gated on a Flickr PRO subscription, a spend
decision. Not stubbed (an adapter with no key to test against is dead code); noted here so
the trigger is a purchase, not a rediscovery. The appeal signal it would carry has no other
free source of comparable independence, so E45's Flickr item waits on the subscription.

**E39 status (2026-07-15): the practical layer and the local-alert path are built.**

- **PracticalScout** is live — pharmacies, toilets, EV charging, shelter and transport
  hubs, near-range only (a pharmacy 30 km ahead is a countdown, not a suggestion). The
  places were already ingested from OSM; E39 added the transport-hub tags (`railway=station`,
  `public_transport=station`, `amenity=bus_station|ferry_terminal`) and the scout that reads
  the `practical` domain by intent.
- **Local alerts** (closures, strikes, disruptions, hazards) are read from RSS feeds by
  `NewsFeedReader` → classified deterministically by `AlertClassifier` (NOT the LLM — the
  fact is the newspaper's and must be citable to it; a keyword detector we can point at beats
  a clever reading we cannot) → materialised as **ephemeral opportunities** with
  citation-only evidence (`MaterializeAlertOpportunities`). The licence line is drawn in
  code: headline + link, never the article body. An alert that cannot be pinned to a real
  place is **dropped, never guessed onto the map**.
- **Still config-gated, by design:** the per-region feed URLs live in `config/sources.php`
  (`news_feeds`), empty by default — a region gets a local-alert layer only after somebody
  adds its feed and reads its terms. **GTFS static feeds and national road-closure APIs
  (e.g. Bison Futé) are the next adapters into this same seam** — they need real endpoints
  to verify, so they are deliberately not stubbed. `MaterializeAlertOpportunities` is
  source-agnostic and they drop straight into it.

### Phase 3 (expansion)

```text
Pack pipeline as the per-region playbook · tourism-board partnerships
· Mapillary imagery understanding · specialist partnerships (Komoot, Michelin-class)
· user-data layer reaches Tier-A aggregate authority
```

---

## 14. Self-hosting the hot path (the cost/latency lever) — MEASURED, 2026-07-14

§12 has always listed "self-hosted routing (cost lever)" as a Phase-2 nicety. **E48 turned it
into a hard constraint, with a number attached.**

On-demand region ingest (E48) learns an area the first time a user explores it. Driving it for
real against **public Overpass**:

| | |
|---|---|
| Rate limiting | 45-second waits, repeatedly |
| Workers | **1** (`supervisor-ingest`, maxProcesses 1 — public Overpass allows ~2 slots per IP, and being rude to it once cost Nantes its entire OSM layer) |
| Region size | ~55 grid boxes |
| **Total** | **~2 hours per region** |

Nearest-first boxes and progressive resolve (E48) mean the user does not *wait* for that — the
feed lights up from the ground under their feet outward. But the ceiling is real, and it is
imposed entirely by somebody else's free, volunteer-funded server.

**Self-hosting turns hours into minutes**, and removes an entire class of failure from the scout
runner. The pieces:

- **Geofabrik extracts** (`download.geofabrik.de`) — free `.osm.pbf` per country/region, daily.
  Sweden is a few hundred MB. Import once with `osm2pgsql`/`imposm` into PostGIS and the data is
  simply *ours*, with no API in the loop. For a region-pack product this is almost certainly
  better than any live API.
- **Own Overpass instance** from that extract — same query surface, no rate limit, no third party.
- **Own Nominatim** (or **Photon**, which is faster for autocomplete) — the public instance caps
  unauthenticated use at ~1 req/s and forbids bulk querying, and we are now in its hot path
  (E48; ROPA §6.1).
- **Valhalla / OSRM / OpenTripPlanner** — self-hosted routing on the same OSM data. Valhalla's
  `/sources_to_targets` matrix is exactly the shape the Stage-B edge routing of served items
  wants (PRD §10), and OTP consumes GTFS for real transit routing.
- **Protomaps** (`.pmtiles`, served by us) as the basemap, if OpenFreeMap ever becomes a
  dependency we mind. MapLibre already speaks it.

> **The rule this implies.** Overpass, Nominatim and Open-Meteo are free *because they are
> volunteer-funded and their terms assume you will not hammer them*. Anything in the hot path —
> tile scouting, geocoding, routing — should be self-hosted rather than treated as
> infrastructure. Geofabrik → PostGIS → our own Overpass/Nominatim/Valhalla is roughly a weekend
> of setup, and it is the difference between a product and a guest.

### 14.1 The OSM *editing* API is not a data source

`api.openstreetmap.org` (the "OSM API v0.6") is the **editing** API for iD and JOSM. Its terms
explicitly forbid using it as a data source for applications; it is heavily rate-limited and
returns raw nodes/ways/relations you would have to assemble into geometry yourself. The data is
fine — it is ODbL and lives happily inside the `places_core` boundary (ODBL-REVIEW §6) — but the
read paths are **Overpass**, a **Geofabrik extract**, or **Overture**. Nothing else.

---

## 15. Image coverage — why most places have no photo (and the free fixes)

**1,479 of 60,962 places have an image (2.4%).** The feed falls back to the paper stripe, as
designed (DESIGN §3), but a wall of stripes is not the product.

The cause is precise, and it is not a licensing problem: **`FetchCommonsImages` only looks up
places that carry a Wikidata link** — it queries Wikidata's `P18` (image) for places whose
`place_source_ids` include `wikidata`. Most OSM long-tail places have no `wikidata=` tag at all,
so they are structurally unreachable by the only image path we have.

Free sources, roughly in order of value-per-hour:

| Source | Licence | Why it helps |
|---|---|---|
| **Commons GeoSearch** (`list=geosearch`, ns 6) | CC (per file) | Finds **geotagged files near a coordinate** — no Wikidata link required. This is the single biggest coverage win available, and it reuses the Commons plumbing we already have. |
| **OSM tags we already fetch and throw away** | ODbL / per-file | OSM POIs frequently carry `image=<url>`, `wikimedia_commons=File:…` and `wikipedia=`. `OsmAdapter` keeps `wikidata` and `wikipedia` and **discards the other two**. Free, already in the payload, currently binned. |
| **Wikipedia `pageimages`** | CC-BY-SA | A place with a `wikipedia=` tag but no Wikidata `P18` can still take the article's lead image. We already store the tag and already call Wikipedia (`FetchWikipediaExtracts`). |
| **Mapillary** | CC-BY-SA | Street-level imagery, free API. Answers "what does this actually look like from the pavement" for places no photographer ever pointed a camera at. |
| **Openverse / Flickr (CC-filtered)** | CC-BY / CC0 | Geotagged CC photos. Needs per-file licence filtering, which the `place_images` schema already carries. |
| **National heritage portals** | Usually permissive | Riksantikvarieämbetet (SE) and POP/Mérimée (FR) — both already adapters — carry imagery we do not currently take. |

**E50 status (2026-07-15): the free trio is BUILT and live.** `FetchPlaceImages` now runs four
free sources in order of confidence — Wikidata P18, the OSM `wikimedia_commons` tag we used to
discard, the Wikipedia lead image, and Commons GeoSearch — each catching what the ones before it
could not. Every path funnels through `CommonsClient::info()`, so the "no attribution → not
served" rule holds whichever source found the photo. Measured live on the Umeå region, one batch
each: OSM tags 18/60, Wikipedia 5/40, **GeoSearch 21/40 (52%)** — geosearch is the widest net, as
predicted, because it needs only a coordinate. The geosearch radius is deliberately tight (120 m):
"a photo geotagged here" must mean the same as "a photo OF this", or coverage is bought with quiet
lies. **Round two also built (2026-07-15): Mapillary + Openverse.** Mapillary (street-level,
coordinate-based, honest "here" — ranks with geosearch) needs a free access token and
degrades to nothing without `MAPILLARY_TOKEN`. Openverse (the CC pool without Flickr's PRO
gate) is **keyless** but name-based, so it is guarded hard — distinctive names only, and the
result title must contain the place name — and it runs DEAD LAST, below every
coordinate-based source, because a name match is a weaker claim than a geotag. Verified
keyless against the live Openverse API. Flickr itself stays blocked on its PRO-subscription
gate.

**Explicitly not Google Places photos.** They cannot be persisted into any world-model table
(ODBL-REVIEW §6, conventions/09) — edge-only, and they cost money per view. A photo is not worth
a licensing incident.

**Not generic stock (Unsplash / Pexels / Pixabay), and not by accident.** A keyword search for a
place returns a nice photo of *a* church, not *that* church — and showing it as if it were the
place is the exact category of unsourced claim this product refuses everywhere else. The paper
stripe is more honest than a wrong photo.

**Also worth knowing:** the `photos` phase runs at the *end* of a region build, so a region being
learned on demand has no images until the whole ingest finishes — the same "waits for everything"
shape that nearest-first ingest and progressive resolve were built to fix (E48).

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
