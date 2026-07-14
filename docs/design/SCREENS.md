# Screen Specifications (Phase 1)

| | |
|---|---|
| **Document status** | Design v1.0 |
| **Companion to** | [DESIGN.md](DESIGN.md) (tokens/components — read first) · [../PRD.md](../PRD.md) §6.6, §8.1, §12, §14.5 · [../ONBOARDING.md](../ONBOARDING.md) · [../SCORING.md](../SCORING.md) §7 |
| **Audience** | Any LLM or human building the UI. Every screen lists its data source and every action's backend meaning. |

Reference mockups: `Brand/Travel companion brand exploration/export/screens/*.png` (turn 3 =
feed/map/detail/dark/empty; turn 4 = calibration/digest).

**Global chrome on every tab screen:** `<AppHeader>` (wordmark + context stamp), content, floating
`<TabBar>` (NOW · MAP · KEPT · JOURNAL). Auth screens and calibration have no tab bar.

**Action semantics (fixed vocabulary → feedback API).** Every action posts to
`POST /api/v1/recommendations/{id}/feedback` (PRD §14.5); these mappings are load-bearing for
learning (SCORING §4.1):

| UI action | `event` | Learner meaning |
|---|---|---|
| **Take me** | `accepted` (+ `metadata.opened_map`/`started_navigation`) | weak positive (η .08) |
| **Keep** / **Remind me** | `saved` | strong intent (η .15) |
| **Kept** (tapped again) / **Remove** (S6) | `unsaved` | nothing — housekeeping, never a verdict |
| **Not for me** | `dismissed` | explicit negative — the "not my thing" affordance (η .25) |
| **Show me these again** (S6) | `undismissed` | retracts the dismissal (see below) |
| "I was here" confirmation | `visited` | golden label (η .30) |
| card served, no interaction | `ignored` (batched on session end) | near-zero (η .02) |

**Undo rule:** *Not for me* shows a **~5-second undo snackbar, and the `dismissed` POST is deferred
until it expires**. At η .25 this is the strongest single signal the Phase 1 learner gets (PRD
§13.3's weak-signal judgment call), and it's reachable by swipe — one mis-tap must not pollute a
taste profile learned from sparse data. No undo needed for *Keep* (reversible in KEPT) or *Take me*.

**The snackbar is not the only undo.** It covers the five seconds in which you notice; it cannot
cover the mis-tap you notice tomorrow, and `dismissed` both hides the card *and* narrows what you
are ever shown again. So the dismissal stays reversible for good, from KEPT (S6) — and reversing it
**retracts what it taught**: `undismissed` applies the exact inverse of the dismiss update
(`w ← w / (1 − η)`, clamped) and decrements the event count, rather than applying some positive η of
its own. "I didn't mean that" is not "I love this."

**Both toggles are latest-wins over an append-only ledger.** The feedback stream is the moat
(PRD §14.5) — a keep is retracted by `unsaved`, a dismissal by `undismissed`, and neither is ever
deleted. `saved`/`unsaved` decides what is on KEPT; `dismissed`/`undismissed` decides what the feed
hides. **The feed is served once and thereafter replayed from the stored recommendations, so the
replay must consult the ledger** (`RankSession::feedFor`) — a dismissal that only hides the card
client-side comes straight back on the next reload.

---

## S1 · NOW — the feed (home)

**Route** `/` (session active) · **Data** `GET /api/v1/explore-sessions/{s}` → feed of 3–5
recommendations, each with title, summary, facets (≤2 shown), friction (`walk_minutes`, price band),
urgency window, and scores metadata.

Layout: header → `<EditorialLede>` (backend-written feed summary) → cards (gap 14) → end note →
tab bar.

- **Card order = server order** (SCORING §7 greedy selection). Never re-sort client-side.
- At most one `<OpportunityCard urgent>` (GO NOW) at top: 1.5px ochre border, ring + "GO NOW ·
  {time}" label, filled *Take me* pill. Ring fraction = remaining/total window.
- Standard cards: facet caps → title → summary → hairline → meta row + text actions (*Take me*
  underlined, *Keep* quiet).
- End note, italic serif, centered: "That's all for now." — scarcity is the product (PRD §12.1).
- **No session yet** → S2 as inline start state. **Feed still computing** → serve cached results
  instantly + paper-stripe placeholder card + quiet "still looking" line (PRD §10 latency budget);
  new cards append below, no reshuffle. **One exception:** an arriving **urgent** item is the only
  card permitted to insert at the top (the GO NOW slot) mid-session — with scroll-position anchoring
  so nothing moves under the reader's thumb. The server guarantees at most one urgent item per feed;
  if a new one supersedes it, the old card demotes to standard styling in place.
- Swipe/long-press affordance on cards may expose *Not for me* (also on detail); don't bury it.

### The feed is alive (E46)

The menu is not fixed for the life of the session. PRD §8.1's own loop — *"re-opening the app yields
a fresh menu, scored against the remaining budget"* — happens **at pull time**, and the client's whole
part in it is two steps: report where you are, then ask again.

- **On open and on focus**, the client POSTs the current position to
  `POST /explore-sessions/{s}/context-events`, then re-pulls the feed. Never on a timer: a re-pull
  while someone is reading moves cards out from under their thumb. Never a permission prompt either —
  it reports only if geolocation is *already* granted (PRD §16, "honest permission UX").
- **The server decides whether you moved**, not the client. Past a drift threshold (`config
  trips.reanchor`, 400 m, discounted by the device's own claimed accuracy) it ranks a **new serve
  batch** from where you now are. The feed payload carries `serve.{group, reason, anchor}`; when
  `group` climbs and `reason` is `move_reanchor`, S1 shows one quiet line — *"You've moved — these are
  picks for where you are now."* It is a note, not an offer, and never a modal: the new cards are
  already below it.
- **"Fresh picks from here"** (`POST /explore-sessions/{s}/refresh`), a quiet action beside *End
  session*. No drift test — the user may not have moved a metre, they may simply have eaten.
- **Dismiss backfills.** Once the deferred `dismissed` POST lands, the feed reloads and the server
  tops the batch back up to `feed_size` — a card slides into the gap rather than the menu simply
  getting shorter. The replacement excludes everything already served in the batch, and a dismissed
  **place** is excluded from every later batch of the session (not just the row that carried it).

Superseded batches are never rewritten: they stay as decision traces (PRD §15.1), and the replayer
replays each serve on its own clock and its own anchor.

## S2 · Session start — "I'm exploring"

**Route** `/` (no active session) · **Action** `POST /api/v1/explore-sessions`
`{origin, time_budget_minutes, travel_mode, heading?, destination_point?}` (PRD §6.6).

One screen, no wizard: italic headline ("How long do you have?"), time chips (45 min / 2 h / 3 h /
All day → minutes), mode chips (`TravelMode`: Walk default · Bike · Drive), optional "heading
somewhere?" collapsed row (destination search → `destination_point`), primary pill **Start
exploring**. Geolocation permission is requested *here*, in context — not on app open. Ends with the
silence promise, italic: "I'll be quiet until something is worth it."

**Permission denied / unavailable (designed state, not an error):** without an origin the product
is dead, so "no" gets a real fallback — a **manual start point** (place search or map pin-drop →
`origin`), brand-voice copy ("Tell me where you're starting from and I'll take it from there."),
and **no permission nagging** (one quiet "use my location" affordance remains on S2 for later).
The same manual-origin path serves desktop use, where precise geolocation is often absent anyway.

## S3 · MAP

**Route** `/map` · **Data** session opportunities + `origin`.

Warm paper map style via **MapLibre GL + vector tiles with a custom paper style** (stack decision in
DESIGN.md §3 — raster tiles can't be restyled; bundle lazy-loads on first MAP open). Never default
OSM/Google colors.
Pins per `<MapPin>` spec: one ochre GO NOW pin (with caps label chip), ink dots for the rest
(lowercase chip), olive-ringed "you". Tapping a pin raises the **floating peek sheet** (14px radius,
GO NOW border when urgent): caps label + walk time, title, one-line practical note, *Take me* pill.
Tap-through → S4. Attribution line (OSM/tiles) in the corner — required (ODBL-REVIEW §6).

## S4 · Opportunity detail

**Route** `/opportunities/{id}` (from card tap or peek) · **Data**
`GET /api/v1/recommendations/{id}/explanation` + the recommendation payload.

Order: back + GO NOW status row → photo (warm treatment; source-attributed) → title (serif 26) →
meta row → **story paragraph** (the evidence-grounded "why now", Karla 14) → hairline → **WHY YOU**
(italic serif — from the explanation endpoint) → hairline → **EVIDENCE** (rows with source + checked
time — source transparency, PRD §16) → sticky action row: *Take me there* (filled) + *Keep*
(outline) → quiet centered *Not for me — fewer like this*.

"Take me there" opens the platform maps app (`geo:`/Apple Maps/Google Maps URL) with the
destination, and posts `accepted` with `started_navigation: true`. After a *Take me*, when the app
regains focus ≥20 min later within ~150 m of the place, ask **"Were you there?"** → `visited` (the
golden label; thresholds are the §18.5 tunables). **Its surface:** a quiet card at the **top of
NOW** on next open — serif italic question ("Did you make it to São Roque?"), two text actions
(*I was there* / *Didn't go*), dismissible, never a modal. It's the single most valuable tap in the
learning loop; it must feel like a friend asking, not a survey. **Semantics:** *I was there* →
`visited` (η .30). *Didn't go* (or dismissing the prompt) posts **no taste signal** — the user
*accepted* this item, so it must never be wired to `dismissed`/η .25; at most log a
non-learning `metadata` event for funnel analytics.

## S5 · Empty feed — silence

Same route as S1 when the server returns zero serveable items. Per `<EmptyFeed>` spec: dashed
circle + ochre dot, italic "Nothing worth interrupting you for.", warm body copy (what I'm
watching), caps footer **NEXT LIKELY MOMENT · {time}** if the backend provides one (from
`WATCHING` items' windows). Never an illustration-of-sadness, never "No results found".

## S6 · KEPT

**Route** `/kept` · **Data** saved recommendations (`saved` feedback), grouped: **Still possible**
(with freshness-checked windows) / **Passed** (muted, window gone). Row = digest-style list line
(title serif 16, time-right, one-line note). Actions: *Take me* (revalidates first), *Remove*.
Kept items whose window re-approaches resurface in the NOW feed — note this on the screen footer.

**"Not for me" lives here too**, as a collapsed section at the foot of the screen: the count, and on
expand one row per dismissal (newest first) with a single action, *Show me these again*. It is the
same question as KEPT asked with the opposite sign — *what have I told you about the things you
showed me* — which is why it is a section and not a screen of its own with its own nav item. An undo
nobody can find is not an undo.

Deliberately quiet, collapsed, and last: it is a **repair tool, not a second feed**. The product's
argument is that it shows you few things (PRD §12.1), and a browsable archive of everything you
rejected is exactly the catalogue we refuse to be. It renders even when nothing is kept — dismissing
everything and keeping nothing is precisely when a user needs the way back, and hiding it behind the
"Nothing kept yet" empty state would bury the undo at the one moment it matters. Unlike KEPT, rows
here are **not** window-checked: nothing is offered, so there is nothing to revalidate.

## S7 · JOURNAL

**Route** `/journal` · **Data** visited/accepted history + past digests, grouped by trip (PRD §6.6
implicit trips), newest first. Trip header: name (editable → `PATCH /api/v1/trips/{trip}`), dates,
city stamps. Rows: visited items (with "I was here" confirmations) as serif titles + date. This is
the seed of "your travel memory belongs to you" — Phase 1 keeps it thin (a list, not a scrapbook).
Trip menu: rename · delete location history (`DELETE /api/v1/trips/{trip}/location-history`).

## S8 · Morning digest

**Route** `/digest/today` (also the JOURNAL top item each morning; no push in Phase 1 — it's a
screen you find) · **Data** `GET /api/v1/trips/{trip}/digest`.

Serif italic greeting written from real context ("Good morning, Lisbon is dry until four."), calm
subline ("Nothing needs deciding now…"), then **one grouped card** (single container, hairline
dividers): item = serif title + right-aligned window ("until ~12:00") + one-line note. Footer: "Save
any to today's map" + *Open map* underline. Evening recap variant: same shell, past-tense lede,
day's visited/kept summary.

## S9 · Onboarding calibration

**Routes** `/welcome` → 9× `/calibrate/{n}` → `/calibrate/practical` → S2. Content **always from
the backend** (`calibration_version`, ONBOARDING.md) — never hard-coded pairs.

- Pair screen (turn-4 mockup): header with "{n} of 9" · `<ProgressSegments>` · italic "Which pulls
  you in?" + "There's no right answer — this is how I learn your taste." · two stacked photo cards
  (image + serif caption; tap = choose, brief ink-border confirm, auto-advance) · centered
  underlined *Skip this one*.
- Practical screen: italic "Two practical things." · walk question (3 pill chips) + food question
  (3 stacked pills); selected = **filled ink** (never terracotta — §1.1 rule 1) · primary **Start
  exploring** · italic "I'll be quiet until something is worth it."
- Interruptible: choices post as they're made; killing the app mid-flow resumes at the next pair.

## S10 · Auth & settings (restyle, not redesign)

Existing starter-kit auth and settings pages restyled with tokens (paper bg, serif headings, pill
buttons, Karla forms). Settings additions (Phase 1): theme (auto/light/dark) · home zone (declared
sensitive zone, PRD §16) · research-consent toggle (full-precision traces — pilot users) · "reset my
taste profile" · account deletion & data export links. Keep shadcn primitives underneath.

## S11 · Offline & degraded network (designed states — dead zones are the normal condition)

France-corridor dead zones and roaming data are expected, not exceptional (PRD risk #10). Phase 1
decisions:

- **NOW offline:** show the **last feed from cache** with an honest staleness line in brand voice —
  "As of 20 minutes ago — I can't check right now." Time-window chips render but urgency never
  *escalates* offline (a stale GO NOW must not shout). No spinners; the paper-stripe placeholder +
  the staleness line are the state.
- **KEPT and JOURNAL are always available offline** (service-worker cached with their data). KEPT
  is the thing you actually need in a dead zone — it's your list.
- **Feedback queues offline:** *Take me / Keep / Not for me / Were you there* actions are stored
  locally and flushed on reconnect (the undo timer runs locally as normal). Losing a golden-label
  tap to a dead zone is not acceptable.
- **"Take me there" still works** — it hands off to the platform maps app, which has its own
  offline story.
- **Session start offline:** S2 explains plainly ("I need a connection to look around — KEPT still
  works.") — no retry-hammering.

---

## Build notes for the implementing LLM

1. Read [DESIGN.md](DESIGN.md) first; tokens land as Tailwind 4 `@theme` in
   `resources/css/app.css`; fonts self-hosted (Fontsource: Newsreader + Karla).
2. Components in `resources/js/components/app/`; pages are Inertia pages calling the same domain
   actions as `/api/v1` (conventions/01 — no business logic in pages).
3. The wordmark string comes from one shared constant (`APP_NAME` → shared Inertia prop) — the name
   is provisional: **interim market name is "Travel Companion"** (`APP_NAME` already carries it);
   a final brand name replaces it via config only (DESIGN.md §1).
4. Mirror enums crossing the wire (`AppealFacet`, `TravelMode`, feedback event) per conventions/02
   frontend-parity rules.
5. Every screen must render sensibly with: zero items, one urgent item, slow network (stripe
   placeholder), **offline (S11)**, **location permission denied (S2)**, and dark mode. These six
   states are part of "done" for each screen.
6. Photo pipeline (real images) is thin in Phase 1: curated/Commons images with per-image
   attribution; the paper-stripe placeholder is the designed fallback, not an error state.
