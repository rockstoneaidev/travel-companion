# Travel Companion AI — Categorisation Taxonomy

| | |
|---|---|
| **Document status** | Design v1.0 |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §6.5, §9.5, §11, §13.2–13.3, §14.2 · [DATA-SOURCES.md](DATA-SOURCES.md) · implemented per [conventions/02-enums.md](conventions/02-enums.md) |

---

## 1. Why this document exists

Half the system depends on a categorisation scheme that the PRD assumed but never defined:
onboarding calibration (§13.2), `personal_fit` scoring (§11), `novelty` and `repetition_penalty`
(§11), `uniqueness` (§9.5), and category-level learning (§13.3). This document defines it.

**The core decision: this is not one taxonomy, it is two orthogonal axes** (plus a third, deferred).
Collapsing them into a single category tree would break the product's own thesis.

### 1.1 The insight

"What a place **is**" and "why it is **worth changing behaviour for**" are independent. A church can
be worth visiting for its architecture, its history, its spiritual quiet, or its photogenic light —
and a given traveller cares about some of those and not others. If we encode only the noun
("church"), we throw away exactly the signal that lets a tiny fresco chapel outrank a grand museum
for a medieval-architecture lover (PRD §9.5). So we separate:

- **Axis 1 — Type** (the noun: what it physically is). A shallow 2-level hierarchy. A *normalisation
  target* that source taxonomies map into — not invented from scratch.
- **Axis 2 — Appeal facets** (the adjective: why it is worth it). A small, orthogonal, hand-designed
  tag set that cross-cuts types. **Taste lives here.**
- **Axis 3 — Experience vibe** (pace, setting, energy, effort). Deferred to Phase 2 — see §7.

The axes are not merely a data-modelling nicety; they are what makes §9.5 computable. The chapel and
the museum share no `Type`, but the chapel carries facets `{architecture, history, offbeat,
spiritual}` and the museum `{art, history, educational}`. A user whose facet weights favour
`architecture + offbeat` ranks the chapel above the museum — the product promise expressed as
arithmetic.

---

## 2. Axis 1 — Type

**What it is.** A two-level hierarchy: a top level of ~10 **domains**, each with a handful of
**leaf types**, ~65 leaves total in v1. Every canonical `place` has exactly **one** leaf type
(and therefore one domain).

**What it is for.** Source normalisation and dedup; `repetition_penalty` ("your third café today");
`novelty` ("you've done three castles this trip"); coarse filtering. Type is **not** where taste is
learned — that is facets (§4). Type needs the specificity that facets deliberately lack.

### 2.1 Domains and leaves (v1)

Leaf lists are v1 starting points, to be refined against real Overture/OSM coverage in the launch
region. Implemented as [`PlaceType`](#6-implementation) with a `domain()` method.

| Domain (`PlaceTypeDomain`) | Leaf types (`PlaceType`, representative) |
|---|---|
| `religious_sacred` | church, cathedral, chapel, monastery, abbey, shrine, temple, sacred_cemetery |
| `historic_heritage` | castle, fortress, ruin, monument, memorial, archaeological_site, historic_house, city_gate, old_town |
| `museum_gallery` | art_museum, history_museum, science_museum, local_museum, house_museum, gallery |
| `nature_landscape` | viewpoint, park, garden, forest, waterfall, lake, beach, cave, cliff, geological_feature, spring |
| `food_drink` | restaurant, cafe, bakery, market, food_producer, winery, brewery, distillery, deli |
| `arts_culture` | theatre, concert_hall, cinema, cultural_center, street_art, artist_studio |
| `architecture_urban` | notable_building, square, bridge, tower, fountain, notable_street |
| `shops_craft` | artisan_workshop, bookshop, antique_shop, specialty_shop, craft_studio |
| `activity_recreation` | walking_trail, cycling_route, beach_recreation, sports_venue, wellness, boat_activity |
| `events` | concert, festival, market_day, exhibition, performance, seasonal_event |
| `practical` *(Phase 2)* | toilet, charging_point, pharmacy, shelter, transport_hub |

`events` is a type domain because event opportunities still resolve to a place, but note that events
carry a `time_window` and are more naturally *opportunities* than *places* (see PRD §2, §14.2).

### 2.2 Granularity discipline

Two levels is deliberate. A single flat 65-way list loses the domain grouping that
`repetition_penalty` needs ("too many `religious_sacred` today", regardless of leaf). A 2,000-leaf
scheme (raw Overture) is unusable for a human curator and pointless for scoring. Ten domains ×
~6 leaves is the sweet spot: specific enough to say "another café", coarse enough to reason about.

---

## 3. Deriving Type from sources (never invent from a blank page)

Because the canonical `places` layer is built from OSM + Overture + Wikidata (the ODbL core —
DATA-SOURCES §2, ODBL-REVIEW §6), **Type is defined *as* the normalisation of those source
taxonomies.** Taxonomy assignment happens in the same pass as entity resolution
(`EntityResolver`, PRD §9.6).

Mapping inputs, in credibility order:

- **Overture `categories`** — the primary signal; a well-structured ~2,000-leaf scheme we collapse
  into our ~65 leaves via a maintained mapping table.
- **OSM primary tags** — `amenity=*`, `tourism=*`, `historic=*`, `natural=*`, `shop=*`.
- **Wikidata `instance of` (P31)** — strongest for heritage/nature where OSM is thin.

Example mapping rows (illustrative; the full table lives in code as reference data, versioned):

| Source signal | → `PlaceType` | → `PlaceTypeDomain` |
|---|---|---|
| Overture `religious_place` / OSM `amenity=place_of_worship` + `building=chapel` | `chapel` | `religious_sacred` |
| OSM `historic=castle` / Wikidata `Q23413` (castle) | `castle` | `historic_heritage` |
| OSM `tourism=viewpoint` | `viewpoint` | `nature_landscape` |
| Overture `winery` / OSM `craft=winery` | `winery` | `food_drink` |
| OSM `tourism=museum` + `museum=art` | `art_museum` | `museum_gallery` |

**Keep the raw tags.** Store the originating Overture category, OSM tags, and Wikidata QID on the
place alongside the normalised `type`. The taxonomy is a *projection*; retaining the source tags
means re-normalising to a new `taxonomy_version` is a batch reprocess, not a re-scrape (§8). This
also fits the `places_core` design — raw open-data tags are open data.

Conflicts (Overture says `restaurant`, OSM says `cafe`) resolve by source credibility
(DATA-SOURCES §1.2); disagreement lowers the place's confidence, exactly as for coordinates and
hours (PRD §9.6).

---

## 4. Axis 2 — Appeal facets

**What it is.** A small orthogonal tag set (~14). A place carries a **set** of facets (0..n), not
one. Implemented as the cross-module [`AppealFacet`](#6-implementation) enum, stored as a JSON array
column.

**What it is for.** This is where taste is learned and matched. `personal_fit` is computed over
facets, because facets are dense and *generalising*: a user who accepts a fresco chapel, a Roman
ruin, and a medieval gate has revealed `history + architecture`, which transfers to a place they
have never seen an instance of. ~14 facet weights converge from a handful of signals; 65 type
weights never would. This is what makes cold start tractable (PRD §11 cold-start handling).

### 4.1 The facets (v1)

| Facet (`AppealFacet`) | The draw is… | Specialist lens (PRD §6, Phase 3) |
|---|---|---|
| `history` | the place's past / story | historian |
| `architecture` | built form, style, structure | architecture expert |
| `nature` | landscape, flora/fauna, natural features | nature guide |
| `scenic` | views, vistas, photogenic light | photographer |
| `food_drink` | culinary experience, produce, drink | food expert |
| `art` | visual/performing art, installations | — |
| `craft` | artisanal making, workshops, tradition | — |
| `spiritual` | contemplative, sacred, quiet | — |
| `local_life` | authentic everyday local culture | local-culture specialist |
| `family` | genuinely good with children | — |
| `active` | walking, hiking, physical engagement | — |
| `offbeat` | quirky, rare, overlooked, unexpected | — |
| `romantic` | intimate, atmospheric for couples | — |
| `educational` | you learn something | — |

The facet set is deliberately close to the Phase 3 specialist lenses (§6). That is not decoration:
when the specialists arrive, each becomes a **facet scorer**. The bones are laid now for free.

### 4.2 Assigning facets

Two sources, combined:

1. **Deterministic rules from Type + source signals.** A `castle` gets `{history, architecture}`
   as a base; a `viewpoint` gets `{scenic, nature}`; a `winery` gets `{food_drink, craft,
   local_life}`. These are *priors*, a floor, not the final set.
2. **LLM tagging from the evidence bundle.** "Offbeat", "romantic", "spiritual" are matters of
   interpretation that rules cannot read off a category. The LLM assigns them **from the stored
   evidence** (descriptions, articles) — which is interpretation of evidence, not fact invention,
   and therefore permitted under PRD §14.4 / conventions/10. Every assignment records
   `prompt_version` and its evidence, like any LLM output.

Base rules (illustrative floor; the LLM adds/removes from evidence):

| `PlaceType` | Base facets |
|---|---|
| `chapel`, `church`, `cathedral` | `spiritual`, `architecture`, `history` |
| `castle`, `fortress`, `ruin` | `history`, `architecture`, `scenic` |
| `viewpoint`, `cliff` | `scenic`, `nature` |
| `waterfall`, `lake`, `forest` | `nature`, `scenic`, `active` |
| `winery`, `food_producer` | `food_drink`, `craft`, `local_life` |
| `market`, `market_day` | `food_drink`, `local_life` |
| `art_museum`, `gallery` | `art`, `educational` |
| `history_museum`, `archaeological_site` | `history`, `educational` |
| `artisan_workshop`, `craft_studio` | `craft`, `local_life` |

---

## 5. Division of labour — which axis feeds what

This table is the operational payoff of separating the axes. Each scoring component reads the axis at
the granularity it needs.

| Consumer (PRD ref) | Reads | Why that axis / granularity |
|---|---|---|
| `personal_fit` (§11) | **facets** | Dense, generalising; converges from sparse signals |
| Onboarding calibration (§13.2) | **facets** | Calibration pairs are facet-separating choices → seed facet priors |
| Category-level learning (§13.3) | **facets** (taste) + **type** (habituation) | Taste on facets; "seen this kind today" on type |
| `uniqueness` (§9.5) | **facets** + the §9.5 signals | Distinctiveness is partly "rare facet combination here" |
| `novelty` (§11) | **type** (+ facets) | "Third castle this trip" needs type specificity |
| `repetition_penalty` (§11) | **type domain** | "Too many `religious_sacred` today" is a domain-level guard |
| Source mapping / dedup (§9.6) | **type** | Reconciling what the thing is |
| Trip-model / time-budget fit | **vibe** (Axis 3, Phase 2) | Relaxation day vs. sightseeing day |

Onboarding and learning operating on the **same** axis (facets) is the design working as intended:
the 60-second calibration seeds exactly the representation that behaviour then refines.

---

## 6. Implementation

Per [conventions/02-enums.md](conventions/02-enums.md): **native PHP backed string enums, stored in
`varchar`/JSON columns — never Postgres native enums, never lookup tables.**

| Enum | Location | Notes |
|---|---|---|
| `PlaceTypeDomain` | `app/Domain/Places/Enums/PlaceTypeDomain.php` | ~10 cases + `practical` (P2). `label()`. |
| `PlaceType` | `app/Domain/Places/Enums/PlaceType.php` | ~65 cases. `domain(): PlaceTypeDomain` and `baseFacets(): array` methods. |
| `AppealFacet` | `app/Enums/AppealFacet.php` | **Cross-module** (Places tags, Profiles weights, Recommendations scoring, Curation) — belongs in `app/Enums`, like `SourceLicense`. |

`OpportunityKind` (the opportunity `type` field, PRD §14.2) is a **separate** enum
(`app/Domain/Opportunities/Enums/OpportunityKind.php`) covering `ephemeral_detour`, `evergreen`,
`event`, etc. — out of scope for this document (PRD gap #6), noted here only so it is not confused
with `PlaceType`.

### 6.1 Columns on `places`

Per [conventions/03-migrations-and-schema.md](conventions/03-migrations-and-schema.md):

```php
$table->string('type', 48)->index();          // PlaceType  (cast: PlaceType::class)
$table->string('type_domain', 32)->index();    // PlaceTypeDomain (denormalised for cheap filtering)
$table->jsonb('facets');                        // list<AppealFacet> (cast: AsEnumCollection::of(AppealFacet::class))
$table->jsonb('source_tags');                   // raw OSM/Overture/Wikidata tags — never discarded (§3)
$table->unsignedSmallInteger('taxonomy_version')->index();
```

`facets` is `jsonb` so it can be queried/indexed (GIN) for "places with facet X in this tile".
`type_domain` is denormalised from `type` for cheap domain-level queries (repetition_penalty); it is
derived, never independently authored.

### 6.2 Frontend parity

`PlaceType`, `PlaceTypeDomain`, and `AppealFacet` cross the wire (onboarding renders facets; the feed
shows type/facets), so each is mirrored into `resources/js/types/enums.ts` with the parity test
(conventions/02 §"Frontend parity"). Onboarding ships facet options from `AppealFacet::options()`,
never hand-typed in TS.

---

## 7. Axis 3 — Experience vibe (deferred to Phase 2)

Orthogonal descriptors of *how the experience feels*, useful for trip-model and time-budget matching
(relaxation day vs. sightseeing day; "I have 45 minutes"):

- `pace` — quick / immersive
- `setting` — indoor / outdoor
- `energy` — quiet / lively
- `effort` — easy / strenuous

Deferred because it only pays off once the **trip model** is real (Phase 2, PRD §6, §8.2). Adding it
later does not disturb the learning representation — facets carry taste; vibe only filters. Ship
Type + facets first.

---

## 8. Versioning & evolution

- The taxonomy is itself versioned (`taxonomy_version`, per PRD §15 "version everything"). Every
  place records the version its `type`/`facets` were assigned under.
- **Revisions are batch reprocesses, not re-scrapes**, because raw `source_tags` are retained (§3).
  Adding a facet, splitting a type, or fixing a mapping = bump the version, re-run the normaliser
  over stored source data, backfill.
- Enum values are `snake_case`, stable, and **never renamed after production** (conventions/02) — a
  rename is a data migration. Add cases freely (a code deploy, no schema migration, because the
  columns are `varchar`/`jsonb`); removing a case is a data migration.
- Expect facets to be the more stable axis (a product decision, ~14 tags) and type leaves the more
  churny one (refined against real source coverage per region).

---

## 9. Summary

| Axis | Cardinality | Per place | Carries | Enum |
|---|---|---|---|---|
| **Type** (domain → leaf) | ~10 → ~65 | exactly one | what it is | `PlaceTypeDomain`, `PlaceType` |
| **Appeal facets** | ~14 | a set (0..n) | why it's worth it → **taste** | `AppealFacet` |
| **Vibe** (Phase 2) | ~4 dims | a set | how it feels | *(deferred)* |

The one-line version: **type is the noun and answers "what is this"; facets are the adjectives and
answer "who is this for" — and because taste is learned on the ~14 shared adjectives rather than the
~65 nouns, a handful of accept/ignore signals generalise to places the traveller has never seen.**
