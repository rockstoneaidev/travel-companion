# Passo — UI Design System

| | |
|---|---|
| **Document status** | Design v1.0 — extracted from `Brand/Travel companion brand exploration` (direction 1a "Warm & analog", refined in turn 3, decided 2026-07-12) |
| **Companion to** | [SCREENS.md](SCREENS.md) (screen specs) · [../PRD.md](../PRD.md) §8.1, §12.1, §13 · [../ONBOARDING.md](../ONBOARDING.md) |
| **Audience** | Any LLM or human building the UI. This document is authoritative for look, feel, tokens, and component anatomy. |

---

## 1. The direction, in one paragraph

**Passo** (Italian: "pace") is the **permanent internal codename** of this design system — code
namespaces (`components/passo/`), doc titles, and token prefixes keep it regardless of what the
product is eventually called. The *market-facing name* is a separate thing: **interim name (decided
2026-07-11): "Travel Companion"** — used in the wordmark, PWA manifest, and app chrome until a
final brand name is chosen (the trademark screen ruled out Passo/Wayside/Meander/Amble). The
wordmark string is a single swappable token (shared prop from `APP_NAME`), never hard-coded in a
component, so the rename later is a config change, not a refactor.
The feel is **a warm, analog travel journal**: paper, ink, one accent. It is quiet, editorial, and personal — the opposite of a booking app. The companion speaks
in the first person, in short serif sentences, and promises silence: *"The light in São Roque is
worth six minutes of your evening. I'll be quiet after this."* The UI's job is to make 3–5 cards
feel like a considered note from a friend, not a results list.

### 1.1 Design principles (locked)

1. **Color means one thing: go now.** Ochre/amber is reserved exclusively for urgency. Facet
   labels, metadata, and chrome are quiet olive-gray. If everything is highlighted, nothing is.
2. **One urgent thing at most.** A feed shows at most one GO NOW card. Urgency is scarce by design.
3. **The ring never appears without its label.** The light-remaining ring gauge always sits beside
   "GO NOW · ~40 MIN" text; alone it reads as a loading spinner.
4. **Action vocabulary is fixed.** Primary **Take me** · defer **Keep** / **Remind me** · reject
   **Not for me**. Never "Book", "Explore", "Discover", "View details", "Learn more".
5. **Silence is a first-class screen.** The empty feed is designed, warm, and confident — never an
   apologetic "no results" state.
6. **Metadata restraint.** Practical facts (walk time, price) are small, quiet, and grouped; the
   title and the "why now" sentence carry the card.

---

## 2. Tokens

Deliver as Tailwind 4 `@theme` variables in `resources/css/app.css`. Names below are the canonical
token names; components reference tokens, never raw hexes.

### 2.1 Color — light ("paper")

| Token | Hex | Use |
|---|---|---|
| `--color-paper` | `#F6F0E4` | app background |
| `--color-card` | `#FCF8EE` | cards, tab bar, sheets |
| `--color-ink` | `#3B2F24` | headings, primary text, selected states |
| `--color-body` | `#5C5142` | body text, editorial ledes |
| `--color-meta` | `#6E6149` | **all readable small text**: practical metadata (walk · price), facet labels, inactive tabs, context stamps (5.35:1 — passes AA) |
| `--color-muted` | `#8A7A5F` | **decorative/redundant text only**: end notes, dashed accents, annotation flourishes (3.7:1 — fails AA at small sizes, §5) |
| `--color-border` | `#E4D9C2` | card borders (1px) |
| `--color-border-soft` | `#EFE4CC` | inner hairlines/dividers |
| `--color-border-strong` | `#CBBB9C` | secondary-button borders, dashed accents |
| `--color-terracotta` | `#C0603A` | **primary action only** (Take me fill, progress fill) |
| `--color-on-terracotta` | `#FCF8EE` | text on terracotta |
| `--color-urgent` | `#C9963F` | GO NOW: card border, ring, map pin (ochre) |
| `--color-urgent-deep` | `#A0741F` | GO NOW label text |
| `--color-urgent-track` | `#EFE0C2` | ring track |
| `--color-olive` | `#7A7A52` | "you" marker, positive-quiet accents |
| `--color-map-bg` | `#EFE8D8` | map canvas |
| `--color-map-road` | `#E4DAC4` | map roads |
| `--color-map-green` | `#DCE3D6` | map parks/water-adjacent |

Shadows (light): resting card `0 1px 2px rgba(59,47,36,.06)` · GO NOW card
`0 4px 16px rgba(160,110,40,.14)` · floating sheet `0 8px 24px rgba(59,47,36,.18)`.

### 2.2 Color — dark ("night paper")

Dark is a *warm* night, not gray. Same token names, dark values:

| Token | Hex |
|---|---|
| `--color-paper` | `#221B13` |
| `--color-card` | `#2C241A` |
| `--color-ink` | `#EFE6D6` |
| `--color-body` | `#C4B69E` |
| `--color-meta` | `#A08F6F` |
| `--color-muted` | `#A08F6F` (same value — the name still exists) |
| `--color-border` | `#3E3323` |
| `--color-border-soft` | `#3E3323` (same value — the name still exists) |
| `--color-terracotta` | `#D97D55` (text on it: `#221B13`) |
| `--color-urgent` | `#D9AC5C` |
| `--color-urgent-deep` | `#D9AC5C` (same in dark) |
| `--color-urgent-track` | `#453824` |
| `--color-olive` | `#8F8F68` |

Dark card shadow: `0 6px 22px rgba(0,0,0,.4)`. Theme follows the OS (`prefers-color-scheme`) with a
manual override in settings; dark is expected on evening use — it is a first-class theme, not an
afterthought.

### 2.3 Typography

Two families, strict division of labor. **Self-host both** (Fontsource/`@font-face` — no Google
Fonts CDN; EU/GDPR).

| Family | Role |
|---|---|
| **Newsreader** (serif; use optical sizing, weights 400–700 + italics) | *The voice.* Wordmark (italic 500), editorial ledes (italic), card/screen titles (roman 500), "why you" narrative (italic), empty-state headlines (italic) |
| **Karla** (sans; 400–700) | *The practical.* Body copy, metadata, labels, buttons, tabs, chips |
| `ui-monospace` | Debug/annotation only (never in product UI) |

Type scale (mobile base; from the mockups):

| Style | Spec |
|---|---|
| Wordmark | Newsreader italic 500, 22px |
| Screen headline (digest/empty/calibration) | Newsreader italic 500, 23–26px / 1.25 |
| Card title (hero) | Newsreader 500, 20px / 1.2 |
| Card title (standard) | Newsreader 500, 18px / 1.2 |
| Detail title | Newsreader 500, 26px / 1.15 |
| Editorial lede | Newsreader italic 400, 15px / 1.45 |
| Body / card summary | Karla 400, 13px / 1.5 |
| Detail body | Karla 400, 14px / 1.55 |
| Metadata row | Karla 500, 11px |
| Facet label (caps) | Karla 500, 10px, tracking `.12em`, uppercase |
| GO NOW label (caps) | Karla 700, 10.5px, tracking `.18em`, uppercase |
| Context stamp (top-right: "LISBON · 17:12") | Karla 500, 10px, tracking `.14em`, uppercase |
| Button (primary) | Karla 700, 12.5–14px |
| Button (text) | Karla 600, 12px, underline with `text-underline-offset: 3px` |
| Tabs | Karla 600 (active) / 500 (inactive), 11px, tracking `.12em`, uppercase |

Two implementation rules on the scale:

- **Author every size in `rem`** (the px values above ÷ 16) so system-level font scaling works —
  a PWA that ignores the user's text-size setting fails the people most likely to need it.
- **Form inputs are never under 16px (1rem)** — iOS Safari auto-zooms on focus of any smaller
  input, which wrecks the layout every time someone taps the destination search or a login field.

### 2.4 Shape, spacing, texture

- Radii: cards 10px · detail/photo blocks 12px · floating map sheet 14px · **all pills/buttons/tab
  bar 99px** (fully rounded).
- Borders: default 1px `--color-border`; **urgent cards 1.5px `--color-urgent`**.
- Screen padding: 18–20px horizontal. Card padding: 16–18px. Card gap: 14px. Bottom padding 90px
  (clears floating tab bar).
- Texture: photos get a subtle warm treatment ("sun-faded 35mm, visible grain" — a CSS
  sepia/contrast filter is enough in v1). Placeholder blocks use the diagonal paper stripe:
  `repeating-linear-gradient(-45deg, #EFE7D4 0 6px, #F6F0E4 6px 12px)`. Analog accents used
  sparingly: dashed borders, one slightly rotated "stamp" chip (≤1.5deg) — never more than one
  rotated element per screen.

### 2.5 Motion & interaction feel

Calm and short: 150–250ms ease-out; cards fade/settle in with ~40ms stagger; no bouncing, no
parallax, no skeleton shimmer (use the paper-stripe placeholder instead). The GO NOW ring animates
its arc once on entry (600ms), then is static — it must never pulse or spin (§1.1 rule 3). Progressive
feed refinement (PRD §10 latency budget): new/better cards slide in below with a quiet
"updated" note, never reshuffling what the user is reading.

---

## 3. Component anatomy (canonical shapes)

React components live in `resources/js/components/passo/` (shadcn primitives underneath where
useful). Names below are the component names to use.

### `<OpportunityCard>` — the core object

```
┌──────────────────────────────────────────┐  bg card · border 1px border · r10
│ FACET · FACET                    (caps)  │  ← meta caps 10px (max 2 facets)
│ Title of the opportunity                 │  ← Newsreader 500 18px ink
│ One- or two-sentence "why now" summary.  │  ← Karla 13px body
│ ───────────────────────────────────────  │  ← hairline border-soft
│ 6 min walk · free        Take me   Keep  │  ← meta 11px | text-buttons
└──────────────────────────────────────────┘
```

### `<OpportunityCard urgent>` — GO NOW variant (max one per feed)

```
┌══════════════════════════════════════════┐  border 1.5px urgent · urgent shadow
│ ◔  GO NOW                     (ring 38px)│  ← ring: track + urgent arc, -90° start
│    ~40 min of light left                 │  ← Karla 11.5px meta
│ Title (Newsreader 500 20px)              │
│ Summary…                                 │
│ 6 min walk · free            [ Take me ] │  ← filled terracotta pill
└══════════════════════════════════════════┘
```

Ring: 38px, stroke 3, `stroke-linecap="round"`, arc fraction = time remaining / window length.

### Buttons

| Kind | Shape |
|---|---|
| Primary (`Take me`, `Start exploring`) | filled `terracotta` pill, `on-terracotta` text, Karla 700 |
| Secondary (`Keep` on detail) | 1px `border-strong` outline pill, ink text |
| Text action (`Take me` on non-hero cards) | Karla 600 ink, underlined (offset 3px) |
| Quiet action (`Keep`, `Not for me`) | Karla 500 `meta`, no underline (interactive text is readable text — never `muted`, §5) |
| Choice pill (calibration) | outline `border-strong` + meta text; **selected = filled ink** (`#3B2F24` / cream text) — selection is ink, not terracotta |

### `<TabBar>` — floating pill

Fixed bottom (24px inset), `card` bg, 1px border, 99px radius, four tabs: **NOW · MAP · KEPT ·
JOURNAL**. Active = ink 600; inactive = meta 500. On desktop (§4) it becomes a left rail.

### Other canonical parts

- **`<AppHeader>`**: wordmark left (Newsreader italic — one token, swappable), context stamp right
  ("LISBON · 17:12" — city from reverse geocode, local time).
- **`<EditorialLede>`**: the italic sentence under the header summarizing the feed ("One thing worth
  going for now. Two keep until tomorrow."). Written by the backend voice layer.
- **`<EvidenceList>`**: label caps "EVIDENCE" + rows of Karla 12px meta ("Open until 19:00 — parish
  site, checked 16:50"). Source transparency is a product requirement (PRD §16).
- **`<WhyYou>`**: label caps "WHY YOU" + Newsreader italic 14px body — the personal-taste
  explanation.
- **`<MapPin>` set**: GO NOW pin 34px ochre disc + 3px card ring + shadow + caps label chip; standard
  pin 18px ink disc + 2.5px card ring + lowercase label chip; "you" 13px card disc + 3px olive ring.
  **Map stack (decided):** the paper map look requires **vector tiles + MapLibre GL JS with a custom
  Passo style** — raster OSM tiles cannot be restyled beyond crude CSS filters. Tile source:
  OpenFreeMap (free, no key) or self-hosted Protomaps/PMTiles as the fallback/cost lever; both are
  the cleanest ODbL-attribution path. MapLibre is ~200KB gzipped — **lazy-load the map bundle on
  first MAP-tab open**, never in the feed's critical path.
- **`<ProgressSegments>`** (calibration): equal flex segments, 3px tall, r2; done/current =
  terracotta, rest = border.
- **`<EmptyFeed>`**: 56px dashed circle (`border-strong`) with an 8px `urgent` dot, italic
  Newsreader headline, body, then caps footer "NEXT LIKELY MOMENT · AROUND 17:00" above a hairline.

---

## 4. Responsive & PWA (decided: one responsive Inertia app)

- **Mobile-first (~400px design width).** This is the primary surface, installed as a PWA on the
  trip.
- **Mid widths (≥ 640px, e.g. iPad portrait):** the phone layout, centered — content column
  max-width ~28rem with generous paper margins, bottom tab bar retained. Never a half-stretched
  hybrid.
- **Desktop / iPad landscape (≥ 1024px):** the same screens in a two-pane layout — persistent left
  rail (wordmark + NOW/MAP/KEPT/JOURNAL + context stamp) replaces the bottom tab bar, content
  column max-width ~28rem, and the MAP pane persistent to the right of the feed where it adds
  value. Never stretch cards full-width; the column *is* the design.
- **PWA:** manifest (name = wordmark token, `background_color` + `theme_color` = light paper —
  the manifest supports only one; per-scheme theming uses paired
  `<meta name="theme-color" media="(prefers-color-scheme: …)">` tags), installable, service worker
  caching the app shell **and the offline data set** (SCREENS.md S11: last feed, KEPT, journal).
  Foreground-only geolocation (PRD §8.1).
- Touch targets ≥ 44px despite the small type. Respect safe-area insets (tab bar sits above them).

## 5. Accessibility notes

- **Measured contrast (light theme):** `meta #6E6149` on paper = 5.35:1 (passes AA at any size);
  `muted #8A7A5F` on paper = 3.7:1 (**fails AA at small sizes** — WCAG's 3:1 relaxation applies
  only ≥24px or ≥18.7px bold; there is no "caps" exemption). Rule: anything a user must *read*
  (facet labels, tabs, timestamps, metadata) uses `meta` or darker; `muted` is reserved for
  decorative/redundant text whose loss costs nothing.
- **GO NOW label (`#A0741F` on card ≈ 4:1) is a deliberate exception**, not a claimed pass: the
  urgent state is triple-encoded (label + ring + 1.5px border + shadow), so the label is never the
  sole carrier of the information.
- Urgency is never color-only: the GO NOW label text + ring + border all co-occur.
- `prefers-reduced-motion`: disable stagger and ring entry animation.
- All tap-targets have visible focus states (2px `urgent` outline offset 2px) for keyboard/desktop.

## 6. Voice & copy rules (the UI writes like the brand)

- First person singular, present tense, brief: "I'll be quiet after this."
- Concrete specifics over adjectives: "The 17:30 batch sells out in twenty minutes", never
  "Amazing local bakery!".
- Time is always honest and specific ("~40 min of light left", "until ~12:00").
- No exclamation marks. No emoji. No "discover/explore/hidden gem" marketing vocabulary.
- Empty states are confident, not apologetic: "Nothing worth interrupting you for."
- Every proactive-feeling sentence reinforces the silence contract ("Quiet until morning, unless
  something can't wait.").
- **Language (decided): the pilot is English-only** — UI chrome and the companion voice — even
  though the pilot regions are Sweden and France. Localization later is a **per-language voice
  re-derivation, not translation**: a first-person italic-serif voice does not survive machine
  translation. Do not bolt on i18n scaffolding in Phase 1.
