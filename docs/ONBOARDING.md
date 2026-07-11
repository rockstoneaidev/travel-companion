# Travel Companion AI — Onboarding Taste Calibration (v1 content)

| | |
|---|---|
| **Document status** | Design v1.0 (`calibration_version: v1`) |
| **Date** | 2026-07-11 |
| **Companion to** | [PRD.md](PRD.md) §13.2 · [TAXONOMY.md](TAXONOMY.md) §4 · [SCORING.md](SCORING.md) §4.1, §6 |

---

## 1. Mechanics (from PRD §13.2 / SCORING §4.1 — restated once)

~60 seconds at first launch: **9 forced-choice pairs** (photo + one line each; "skip" allowed, no
"both") followed by **2 practical questions**. Each pair is constructed to *separate facets*: the
chosen side's facets get `(target 1, η 0.20)`, the rejected side's `(target 0, η 0.10)` — the same
update rule behaviour then refines. Completing calibration sets `α₀ = 0.4` (SCORING §6). Choices are
stored as `profile_signals` rows with `calibration_version`.

Images: Wikimedia Commons (check per-file license, attribute) or own photos; generic-European
scenes, not launch-region landmarks (we're probing taste, not recognition).

## 2. The nine pairs

| # | Option A | A facets | Option B | B facets |
|---|---|---|---|---|
| 1 | Tiny medieval chapel with faded frescoes, door ajar | `spiritual, architecture, history, offbeat` | Grand national art museum, marble halls | `art, educational` |
| 2 | Morning market stall, locals queuing for one cheese | `food_drink, local_life` | Candle-lit tasting menu, seven courses | `food_drink, romantic` |
| 3 | Clifftop viewpoint after a 40-minute walk | `nature, scenic, active` | Old-town café terrace, watching the square | `local_life, food_drink` |
| 4 | Glassblower's workshop, artisan mid-demonstration | `craft, local_life, educational` | Contemporary gallery in a converted warehouse | `art, offbeat` |
| 5 | Ruined hilltop castle, no ticket booth, big views | `history, scenic, active, offbeat` | Writer's preserved home, rooms as they were left | `history, educational` |
| 6 | Harbour walk at golden hour | `scenic, romantic` | Live trio in a cellar bar, locals' night out | `art, local_life` |
| 7 | Botanical garden, greenhouse and picnic lawns | `nature, family` | Alley of street art, half-hidden courtyards | `art, offbeat, active` |
| 8 | Island swim spot and picnic, short ferry | `nature, active, family` | Guided walk: one street, five building styles | `architecture, educational` |
| 9 | Hole-in-the-wall bakery famous for a single pastry | `food_drink, local_life, offbeat` | Rooftop bar with the city at your feet | `scenic, romantic` |

**Coverage check** (each of the 14 facets probed): history ×2, architecture ×2, nature ×3,
scenic ×4, food_drink ×4, art ×4, craft ×1, spiritual ×1, local_life ×5, family ×2, active ×4,
offbeat ×5, romantic ×3, educational ×4. `craft` and `spiritual` are single-probe — acceptable for
v1; behaviour refines them (flagged for the calibration_version v2 rebalance).

## 3. The two practical questions (seed friction thresholds, not taste)

These feed `friction_penalty` inputs (SCORING §5.1), not facet weights:

1. **"How far do you happily walk for something good?"** → 10 / 20 / 40+ minutes → `walk_tolerance`
   (default 15 if skipped).
2. **"A memorable food stop is worth…"** → "keep it cheap" / "mid" / "price doesn't matter" →
   price band.

## 4. Rules

- Pair order randomised; A/B sides randomised (no position bias in the priors).
- Skipped pairs apply no update; skipping everything leaves α₀ = 0 (pure cold vectors — SCORING §6).
- Copy is written in the user's app language; facet vectors are language-independent.
- The pair set, facet vectors, and η values version under `calibration_version` (a
  `profile_model_version` concern — SCORING §9.3): changing a pair or vector mints v2.
- Frontend gets pairs from the backend (options endpoint / Inertia props), never hard-coded
  (conventions/02 frontend-parity spirit).
