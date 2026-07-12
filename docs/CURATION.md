# Travel Companion AI — Curated Layer & Regional Knowledge Packs

| | |
|---|---|
| **Document status** | Design v1.0 |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §9.4, §14.2 · [DATA-SOURCES.md](DATA-SOURCES.md) §8 · [ENTITY-RESOLUTION.md](ENTITY-RESOLUTION.md) · [ADMIN.md](ADMIN.md) (curation tooling) · [conventions/10](conventions/10-llm-usage.md) |

---

## 1. Why this document exists

The curated layer is the moat (PRD §9.4) and DATA-SOURCES §8 defines Regional Knowledge Packs
conceptually — but neither pins the schema or the build pipeline. This does both, and sets the
concrete pack plan for the two launch contexts now decided (PRD §8.0):

- **Stockholm (test region)** — founder-local (Liljeholmen base), used to validate the entire
  pipeline before the trip.
- **The France-trip corridor (pilot)** — Paris, Nantes, Bordeaux, Toulouse, Nice/Riviera, Lyon,
  Dijon/Burgundy, July 27 – Aug 7, 2026.

## 2. Data model

```text
curated_items
  id, place_id (nullable until grounded), pack_id (nullable — items can be pack-less)
  title, claim              (the "why this is special" — one to three sentences)
  facets[]                  (AppealFacet json array — same axis as everything else)
  evidence[]                (jsonb: url, source type, license, excerpt, retrieved_at — the
                             grounding; follows the source's license, e.g. CC BY-SA attribution)
  status                    (CurationStatus enum: draft → needs_grounding → in_review →
                             approved | rejected | archived)
  authored_by               (human | llm; llm rows carry prompt_version)
  language, region_slug, created_by, reviewed_by, timestamps

packs                       (Regional Knowledge Packs — DATA-SOURCES §8)
  id, region_slug, name, status, pack_version, geometry (or H3 set), timestamps

pack_sections
  pack_id, section          (PackSection enum: identity, seasonal, food_drink, heritage,
                             nature, now, craft, stories)
  content                   (jsonb: structured claims, each grounded to place_ids/evidence)
  ttl_class                 ("now" hourly–daily; seasonal quarterly; identity/heritage yearly)
```

Licensing: `curated_items` and `packs` are **proprietary layers keyed by `place_id`** — exactly the
"independent data types" that stay out of the ODbL core (ODBL-REVIEW §6). Evidence excerpts carry
their own source licenses and attribution obligations.

## 3. Build pipeline (semi-automated — DATA-SOURCES §8, made operational)

```text
1. Harvest    Per region: Wikivoyage + Wikipedia + tourism boards + blog RSS + batch
              Reddit/YouTube mining (DATA-SOURCES §6 rules: claims + provenance, never bulk text).
2. Draft      LLM (capable tier — Gemini 3.5 Flash, PRD Appendix A) drafts curated_items from the
              harvested evidence bundle only (conventions/10). status: draft.
3. Ground     Each item is linked to a canonical place via ENTITY-RESOLUTION.md machinery (or a
              places search). No match → needs_grounding (human finds/creates the place or rejects).
4. Review     Human pass in the admin console (ADMIN.md): approve / edit / reject. Mandatory for
              launch-region packs (P1); spot-check sampling later (P3).
5. Publish    approved items become CuratedScout candidates (Tier A credibility after review —
              DATA-SOURCES §1.2); pack_version bumps; packs feed scout context and the
              explanation layer.
6. Refresh    per-section TTLs; "now" sections regenerate from event/news sources.
```

The review gate is what turns LLM drafts into Tier-A evidence. **An unreviewed draft is never
served** — it would launder LLM text into the evidence chain (conventions/10 violation).

## 4. The pack plan (decided)

Reality check: the trip starts ~2 weeks out. Curation effort is nights-weighted and

| Pack | Nights | Target (approved items) | Priority notes |
|---|---|---|---|
| `stockholm` | (home) | 25–40 | **Done** (31 approved, published v1). Renamed from `stockholm-test` and widened to the whole municipality, 2026-07-14 — see note below. |
| `paris` | 3 total (1+2) | 40+ | Two stays (arrival + final); deepest pack |
| `nice-riviera` | 2 | 30 | Includes walkable old town + coastal opportunities (tide/light moments) |
| `nantes` | 2 | 30 | |
| `dijon-burgundy` | 1 | 25 | The PRD's own vineyard thesis territory — small but dense; DATAtourisme/Mérimée strong here |
| `lyon` | 1 | 20 | |
| `bordeaux` | 1 | 20 | |
| `toulouse` | 1 | 20 | |

Per-city focus: items within walking range of the likely exploration zones (old town + station/hotel
areas), weighted toward `offbeat`/`local_life`/`food_drink` — the facets Google Maps is worst at.
The trip itself doubles as the **concierge test** (PRD §8.0): every "we found this ourselves and the
app missed it" moment becomes a curated item + gold trace.

**Stockholm is the home region, not a test region (2026-07-14).** It was originally `stockholm-test`,
a 93 km² box around Liljeholmen/Södermalm/Gamla stan — right while it was a pipeline test, wrong now
that it is where the app is actually used. A feed that goes quiet the moment you walk to Farsta or
Kista has failed. It now covers **Stockholms kommun** (~584 km²: Skärholmen and Farsta in the south up
to Kista and Akalla, Hässelby west to Djurgården east) and is keyed `stockholm`. The rename was a
migration, not a re-do: `region_slug` is only a label — CuratedScout serves by `place_id` and tile —
so the published pack and all 31 approved items carried over untouched.

It stops at the municipal boundary on purpose. Solna, Sundbyberg, Lidingö and Nacka are their own
municipalities: real places worth having, but as their own region rather than a quietly expanding box.

Sweden note: Stockholm runs on the global open core (OSM is excellent in Sweden) + visitstockholm
events + Riksantikvarieämbetet/K-samsök open heritage data — see DATA-SOURCES addendum.

## 5. Effort playbook (to be refined into the P3 template)

Per city pack, budget ~4–6 focused hours: 1h harvest configuration, 1h LLM draft + grounding triage,
2–3h human review/editing, 30min on-the-ground validation where possible. Log actual hours per pack
— that number *is* the Phase 3 expansion cost model (PRD §9.4 densification playbook).

## 6. Module placement

`Domain/Curation/` owns `curated_items`, `packs`, the pipeline actions (`DraftCuratedItems`,
`GroundCuratedItem`, `ApproveCuratedItem`, `PublishPack`), and `CurationStatus`/`PackSection` enums
(conventions/01–02). CuratedScout reads only `approved` items via a Curation contract.
