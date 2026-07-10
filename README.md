# Travel Companion AI

An **agentic travel companion** — an AI that actively helps travelers discover memorable experiences in the real world, without requiring them to search, plan, or ask.

Today's travel apps are reactive: they wait for the user to initiate. This product inverts the model. The companion behaves like an experienced local friend who quietly accompanies you — it understands your context, learns your preferences from behavior, and only speaks when something is genuinely worth your attention.

> *"You're about to pass a family-owned vineyard that still produces wine using methods dating back over 200 years. It closes in 40 minutes and is only a six-minute detour. Based on what you've enjoyed earlier on this trip, I think you'll love it."*

**Core philosophy: Don't help people search. Help them discover.**

The system is built around **opportunities**, not places: a place is static; an opportunity exists because the *current moment* makes that place especially valuable. Opportunities are created by scouts over a multi-source world model, scored for personal fit, uniqueness, urgency, and route fit — and most are never shown. **Silence is a feature.**

## Status

📋 **Planning phase.** The product is fully specified in `docs/`; implementation has not started.

## Documentation

| Document | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | Full product requirements: vision, phasing (pull-first MVP → proactive companion), the four-model Trip Brain, candidate pipeline, scoring, notification policy, architecture, privacy, risks |
| [docs/DATA-SOURCES.md](docs/DATA-SOURCES.md) | World-model source catalog: open canonical core vs. proprietary enrichment edge, credibility tiers, Regional Knowledge Packs, per-phase adapter roadmap |
| [docs/ODBL-REVIEW.md](docs/ODBL-REVIEW.md) | ODbL share-alike analysis and the resulting "open core, proprietary shell" database architecture (pending counsel confirmation) |

## Planned stack

- **Backend:** Laravel modular monolith · PostgreSQL + PostGIS + pgvector · Redis + Horizon
- **Client:** React web/PWA first (pull-based MVP); native mobile when proactive Trip Mode ships
- **World model:** OSM, Overture Maps, Wikidata, Wikivoyage, DATAtourisme + curated regional layer; Google Places as live enrichment only
- **LLM:** evidence-grounded summarization, comparison, and recommendation wording — never a source of facts

## Phasing (summary)

1. **Phase 0** — concierge test + curated content for one launch region + trip replayer
2. **Phase 1** — pull-based Explore Mode MVP (foreground-only, no push): "open the app and it finds one thing nearby you would have missed"
3. **Phase 2** — proactive Trip Mode: background context, geofences, budgeted notifications
4. **Phase 3** — deep companion: cross-trip memory, story-driven recommendations, regional expansion

---

© Rockstone. Contains information from OpenStreetMap (© OpenStreetMap contributors, ODbL) and other open data sources once implemented — see [docs/ODBL-REVIEW.md](docs/ODBL-REVIEW.md).
