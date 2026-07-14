# ROPA — Records of Processing Activities

**Travel Companion AI** · GDPR Article 30(1)
**Status: DRAFT — not reviewed by a lawyer.** Closes DPIA §7.3.
**Version:** 0.1 (2026-07-12) · Privacy policy version: `config/privacy.php` → `v1`

---

## 0. Why this exists, and why the exemption does not apply

Art. 30(5) exempts organisations under 250 people — **unless** the processing is *not
occasional*, or is *likely to result in a risk*, or involves **Art. 9 data**. We are not
occasional (it runs every time someone opens the app), we assessed the risk as high enough
to require a DPIA, and per DPIA §3.2 the taste profile can infer religious belief. **All
three limbs of the exemption fail.** A ROPA is required.

This document is the controller's internal record. It is not user-facing — that is
[`PRIVACY-NOTICE.md`](PRIVACY-NOTICE.md). It must be producible to the supervisory
authority on request (Art. 30(4)), which in practice means: keep it current, and keep it
somewhere you can find it in an hour, not a week.

**Maintenance rule:** this file is wrong the moment a migration adds a column holding
personal data, or a `Http::` call sends user data to a new host. Both are cheap to detect
and are listed in §8.

---

## 1. Controller (Art. 30(1)(a))

| | |
|---|---|
| **Controller** | Mats Bergsten, a natural person (no legal entity yet) |
| **Contact** | `rockstoneaidev@gmail.com` |
| **Establishment** | Sweden |
| **Lead supervisory authority** | **IMY** — Integritetsskyddsmyndigheten (Sweden) |
| **DPO** | None. Not required (Art. 37): no public authority, and three pilot users is not "large scale". **Re-test on growth** — DPIA §7.4. |
| **EU representative (Art. 27)** | Not required — the controller is established in the EU. |
| **Joint controllers** | None. |

> **OPEN — a business decision with a legal consequence.** There is no company, so there
> is no corporate veil: the controller is personally exposed under Art. 82 (civil claims)
> and Art. 83 (fines). Incorporating before the pilot grows is the obvious mitigation and
> is the controller's call, not the lawyer's. See DPIA §2.1.

---

## 2. Categories of data subject

| Group | Who | Rough number today |
|---|---|---|
| **Pilot users** | Adults known personally to the controller, using the app | 3 |
| **Admin / staff users** | The controller, acting through `/admin` | 1 |

No children. No employees. No customers of a third party. Registration is allowlisted
(`ALLOWED_REGISTRATION_EMAILS`) precisely so this table cannot grow by accident.

> **Note on children.** We do not knowingly process children's data and have no age gate.
> That is defensible only while registration is allowlisted. **An age assurance decision
> is required before open registration** (Art. 8) — added as an OPEN item in §9.

---

## 3. Purposes and lawful bases (Art. 30(1)(b))

The DPIA (§4.2) decided consent for the personalisation but is **silent on the basis for
the core service**. This table closes that gap. Where it goes beyond the DPIA it says so.

| # | Purpose | Personal data used | Art. 6 basis | Art. 9 basis |
|---|---|---|---|---|
| **P0** | Create and operate an account; authenticate | Name, email, password hash, OAuth identifiers | **6(1)(b)** — performance of a contract | n/a |
| **P1** | Recommend nearby opportunities worth the user's time | Precise location, time budget, travel mode, weather context | **6(1)(b)** — objectively necessary for the service requested (see note) | n/a |
| **P2** | Personalise those recommendations (the taste profile) | Calibration answers, feedback events, inferred facet weights | **6(1)(a)** — consent | **9(2)(a)** — **explicit** consent (DPIA §3.2) |
| **P3** | Explain a recommendation ("why did I get this?") | Decision traces: every sub-score and input | **6(1)(b)** — the explanation is part of the service; and **6(1)(f)** as a fallback | n/a |
| **P4** | Improve the ranking model (replay against gold traces) | Full-precision traces, retained past the 30-day clock | **6(1)(a)** — separate, opt-in consent | n/a |
| **P5** | Security, abuse prevention, operating the service | Auth events, request telemetry, error logs | **6(1)(f)** — legitimate interests | n/a |
| **P6** | Comply with legal obligations (respond to rights requests, breach records) | Whatever the request concerns | **6(1)(c)** | n/a |

**Note on P1 and Art. 6(1)(b) — this is the one judgement call in the table.**

EDPB Guidelines 2/2019 read 6(1)(b) narrowly: the processing must be *objectively
necessary* for a service the user actually requested, not merely useful to the provider.
Location clears that bar without strain — you cannot recommend what is *near someone*
without knowing where they are. Consent would actually be the *weaker* choice here,
because consent must be freely given (Art. 4(11)) and a "consent" you must grant or the
product does nothing is not free. That is precisely the situation 6(1)(b) exists for.

**But 6(1)(b) requires a contract to exist.** There is no Terms of Service in this repo.
Today that is papered over by the fact that all three users are friends of the controller;
it does not survive the first stranger. **OPEN — §9.**

**Why P2 is consent and not legitimate interests.** The personalisation is a "systematic
and extensive evaluation of personal aspects". Squeezing it into 6(1)(f) is the kind of
move that reads badly in a regulator's summary, and the balancing test would be close at
best. Consent is honest, and the product is built to survive a refusal: α = 0 and the
cold-start ranking is a supported, tested outcome (SCORING §6), not a degraded error state.

---

## 4. Categories of personal data (Art. 30(1)(c))

Enumerated from the schema, not from memory. Citations are to `database/migrations/`.

### 4.1 Product data

| Category | Fields | Table | Special category? |
|---|---|---|---|
| Identity | `name`, `email`, `password` (nullable — Google sign-in), `email_verified_at` | `users` | No |
| Federated identity | `provider`, `provider_user_id` (Google `sub`), `email`, `name`, `avatar_url`, `last_login_at` | `social_accounts` | No |
| **Precise location** | `location` (geography Point 4326), `accuracy_meters` | `context_events` | No — **but see §5** |
| **Precise location** | `origin`, `destination_point` | `explore_sessions` | No — **but see §5** |
| **Precise location** | `anchor_point` | `trips` | No — **but see §5** |
| **Precise location** | `anchor` (geography Point 4326 — where the user stood when a feed batch was ranked; **one row per serve, so a session holds a trail of these, not one point**) | `recommendations` | No — **but see §5** |
| **Declared home** | `home_zone_center`, `home_zone_radius_meters` | `users` | No — **but it is the user's home address** |
| Coarse location | `h3_index`, `origin_h3_index`, `anchor_h3_index` (res-8, ~0.7 km²) | `context_events`, `explore_sessions`, `recommendations` | No |
| Device / situational | `battery_level`, `is_low_power_mode`, `app_state`, `movement_mode`, `speed_mps`, `heading` | `context_events` | No |
| **Companions** | `companions` (jsonb — who the user is travelling with) | `context_events` | No, but personal data *about third parties* — see §4.4 |
| **Inferred preferences** | `facet_weights` (jsonb), `walk_tolerance_minutes`, `price_band`, `event_counts` | `user_taste_profiles` | **Potentially Art. 9 — see §5** |
| **Calibration answers** | `chosen_side`, `chosen_facets`, `rejected_facets` | `profile_signals` | **Potentially Art. 9 — see §5** |
| Behavioural | `event` (accepted / kept / dismissed / **visited**), `metadata` | `recommendation_feedback` | No — but it is what the profile is inferred *from* |
| Decision traces | `scores`, `score_inputs` (jsonb — **contains candidate lat/lng**), `coverage_flags` | `recommendations` | No |
| Observed weather | `weather` (jsonb — temp, precipitation, WMO code, cloud), `weather_observed_at` | `explore_sessions` | No — environmental, not personal. See the note below. |
| **Spend records** | `user_id`, `occurred_at`, `trip_id` / `session_id` / `recommendation_id` / `opportunity_id`, `h3_cell` (res-8), token counts, money | `cost_events` | No — but see the note below |
| Free text | `name` (user-chosen trip name) | `trips` | No |
| Provenance | `context_source` (`device` \| `emulated`) | `explore_sessions`, `context_events`, `recommendations` | No — not personal data, but it is what **keeps** operator testing out of the learning and cost records below (ADMIN §6). Emulated rows are an operator's own account, never a traveller's. |

**On the weather snapshot, which is kept indefinitely and deliberately not coarsened.**
`explore_sessions.weather` records what the sky was doing over the session's tile at the moment we
ranked under it. It is retained for good, because it is the journal's memory of a trip — and
because it cannot be recovered later: Open-Meteo's forecast endpoint answers "what is the sky doing
now", not "what was last August like", and the LLM is never a source of facts.

Keeping it is not a retention regression, and the reason is worth stating precisely rather than
asserting. The row it hangs on already carries the user, the timestamp and (after coarsening) the
H3 cell, indefinitely and by design. Adding *"14°C, raining"* to a row that already says *"this
person was in this hex at this hour"* discloses nothing a reader could not derive from a public
weather archive given the cell and the time. It is **environmental context, not an additional fact
about the person** — so the retention pass hard-deletes the coordinate (which is sensitive) and
leaves the sky (which is not), and account deletion takes it via the existing `explore_sessions`
cascade.

**`cost_events` is personal data, and it was tempting to pretend otherwise** (E24, docs/COST.md §10).
It looks like an accounting table. It is also a timestamped log of *when* a named person used the
app, *how hard*, and *roughly where* — which, joined against nothing at all, is a movement and
usage record. That it exists to answer "what did this cost us" does not change what it contains.

- **Lawful basis:** legitimate interests (Art. 6(1)(f)) — billing integrity, abuse and runaway-cost
  prevention, capacity planning. Narrow, operational, and it does not feed the product or the
  taste profile. *(Balancing test: the data is not used to make any decision about the person; the
  identifying columns are dropped at 90 days; the person can have them dropped immediately.)*
- **Not** used for personalisation, learning, or ranking. If that ever changes, the basis changes
  with it, and this row is wrong until someone updates it.
| Consent records | `profiling_consent_at`, `profiling_consent_version`, `research_consent` | `users` | The record of an Art. 9 consent |

### 4.2 Auth and operational data

| Category | Fields | Table / store | Note |
|---|---|---|---|
| Session | `ip_address`, `user_agent`, `payload` | `sessions` | **Currently unused** — `SESSION_DRIVER=redis`, so this lives in Redis, not Postgres |
| API tokens | hashed `token`, `abilities`, `last_used_at` | `personal_access_tokens` | |
| Password reset | `email`, `token` | `password_reset_tokens` | Keyed by email, not `user_id` |
| Roles | `model_id` → user | `model_has_roles` etc. (spatie) | Admin only |
| Admin audit | `causer_id` (the admin), `subject_id` (the user), `properties` (role changes) | `activity_log` | Written only by `SyncUserRoles` / `AssignRole` |
| **Telemetry** | `key` — **contains user ids and outbound request URLs** | `pulse_entries`, `pulse_aggregates`, `pulse_values` | **See §7.2 — this is a live problem** |
| Queue payloads | serialized job args (may contain user ids) | Redis (`QUEUE_CONNECTION=redis`) | |
| Cache | Google opening hours (per place), weather (per tile) | Redis | Google hours TTL 600 s |

### 4.3 What is *not* personal data

The world model — `places_core`, `place_source_ids`, `source_items`, `opportunities`,
`opportunity_evidence`, `place_images`, `packs`, `scout_runs`, `place_match_decisions` —
holds no personal data. It is the map, not the traveller. (`opportunities` becomes
*user-linkable* through `recommendations`, but the row itself is not about a person.)

### 4.4 Third-party personal data — `context_events.companions`

`companions` records who the user is travelling with. Depending on what it holds, it is
personal data about **someone who never signed up and cannot exercise any right against
us**, because we have no way to identify or contact them.

**OPEN — §9.** Either (a) constrain the field to a non-identifying enum (`solo` / `couple`
/ `family` / `group` — which is all the scoring model actually needs), or (b) accept that
we hold third-party personal data and handle Art. 14 for it, which for a pilot is
disproportionate. **(a) is obviously right and is probably already the intent** — but the
column is a free `jsonb` and nothing in the schema enforces it.

---

## 5. Special categories (Art. 9) — recorded because it is true, not because we asked

We do not *ask* for special-category data. We nonetheless process it **by inference**.

The taxonomy (`docs/TAXONOMY.md`) has a `religious_sacred` place-type domain and a
`spiritual` appeal facet. The taste profile learns a weight for exactly those. A user who
repeatedly chooses and visits churches, mosques or synagogues accumulates a vector that
is, in substance, **an inferred statement about their religious belief** — Art. 9(1) data.
The CJEU has held that data from which special-category data can be *indirectly deduced*
falls under Art. 9 (C-184/20, *OT v Vyriausybinė*).

This reaches **three** tables, not one — and the third is the one that is easy to miss:

| Table | Why it is Art. 9 |
|---|---|
| `user_taste_profiles.facet_weights` | The inferred vector itself |
| `profile_signals` | The calibration answers the vector is derived from — "chose the chapel over the grand museum", nine times |
| `recommendation_feedback` | The behavioural ledger: *visited* a mosque, repeatedly |

**Lawful basis: Art. 9(2)(a), explicit consent.** Implemented and gated in code —
`FacetWeightLearner` refuses to move a weight without it, and the calibration POST refuses
to write `profile_signals` without it (commit `ae7cb10`). Recorded per-user with a version
(`users.profiling_consent_at` / `profiling_consent_version`), so widening what the profile
infers invalidates every existing consent rather than silently stretching it.

The consent text, and the exact conditions it must satisfy, are in
[`CONSENT.md`](CONSENT.md).

**Health and sexual orientation** follow from the same mechanism (a pattern around a
clinic; venue types not in today's taxonomy). The consent is drafted to cover the
mechanism, not just the religion example — because the mechanism is what is generic.

---

## 6. Recipients (Art. 30(1)(d)) and transfers (Art. 30(1)(e))

Verified against the code, not against the vendor list. Every outbound call in `app/`
goes through the `Http` facade and is enumerated here. Full detail, DPA status and the
open contractual questions are in [`PROCESSORS.md`](PROCESSORS.md).

| Recipient | Role | What it receives | Country | Transfer basis |
|---|---|---|---|---|
| **Hetzner** | Processor — hosting | Everything (it is the database) | 🇩🇪 Germany | **No transfer** (EEA) |
| **Google Routes** | Processor | **The user's precise origin coordinates** + a destination | 🇺🇸 US | DPF / SCCs — §6.1 |
| **Google Places** | Processor | A *place's* name + coordinates. **No user data.** | 🇺🇸 US | DPF / SCCs |
| **Google Gemini** | Processor | Place evidence + `part_of_day`, `travel_mode`, `walk_minutes`, `city_name`. **No identity, no profile, no coordinates.** | 🇺🇸 US | DPF / SCCs — **and see the training question, §6.2** |
| **Google (OAuth / Sign-In)** | **Independent controller**, not a processor | The sign-in itself; returns `sub`, email, name, avatar | 🇺🇸 US | Google's own basis; ours is 6(1)(b) |
| **Open-Meteo** | Recipient | **The user's precise origin coordinates** — *not* a tile centroid, despite the tile-level cache. See §7.1. | 🇩🇪 Germany | **No transfer** (EEA) |
| **Resend** | Processor — transactional email | Recipient **email address** + message body (password reset, verification) | 🇺🇸 US | DPF / SCCs — **absent from the DPIA entirely** |
| Overpass / OSM, Wikidata, Wikimedia, DATAtourisme, Mérimée | Not processors | Region bounding boxes. **No user data at all.** | — | n/a |
| **Nominatim** (OSM) | Not a processor | **A res-8 tile centroid** (~0.74 km²) — asked "what is this place called?" when a user opens a session somewhere we have never ingested (E48). **Never the user's own coordinate**: see the note below. | 🇩🇪 Germany (OSMF) | **No transfer** (EEA) |

### 6.1 The sharpest transfer

**Google Routes receives where a person is standing, right now, at full precision**, and
sends it to a US company. It is unavoidable if we want a true walking time (PRD §10 Stage
B). It is mitigated — no user id, no session id, no cookie travels with the request
(`GoogleRoutes.php`, and a test asserts it) — so Google receives *a coordinate pair, not a
person*. That mitigation is real and worth keeping. It is not the same as the transfer not
happening, and this record should not pretend otherwise.

### 6.2 Two things that must be confirmed before launch

- **Which Google entity, under which terms.** Maps Platform and the Gemini API are
  different products with different terms. It is a mistake to assume a DPA covering one
  covers the other. → `PROCESSORS.md` §3.
- ~~**Whether Gemini input is used for training.**~~ **Settled 2026-07-12: we are on the paid
  tier**, where Google does not use API input to train its models. Had we been on the free
  tier — where it generally does — place evidence + city + part-of-day would have been
  processed for *Google's* purposes rather than ours: a processor acting outside the
  controller's instructions (Art. 28(3)(a)), for a purpose disclosed to nobody.
  **Re-check on any change to billing.** The lawfulness of the LLM pipeline now rests on a
  payment status, which is an uncomfortable dependency and worth naming as one: downgrading
  the API key would silently make the processing unlawful, and nothing in the code would
  notice.

### 6.3 The DPF is not a permanent answer

The EU–US Data Privacy Framework is an adequacy decision (Art. 45), and a transfer to a
DPF-certified recipient is lawful without SCCs. It is also **under active legal challenge**
(*Latombe*). Plan for it falling rather than be surprised: confirm SCCs exist as a
contractual fallback in each Google agreement, and know which processing stops if adequacy
is withdrawn. (Answer today: Routes and Gemini stop; Places is US-bound but carries no user
data, so it survives.)

---

### 6.1 Nominatim, and a mistake worth recording (E48, 2026-07-14)

On-demand region ingest (E48) asks Nominatim to name an area the first time anyone explores it.
The first implementation passed **the session's own origin** — a real traveller's exact
coordinate, sent to a third party, to answer a question that never needed it.

That is precisely open finding **B3** (Open-Meteo receives raw coordinates rather than a tile
centroid) repeated in a new place, and it would have falsified the row above it: *"no user data
at all"*.

It is fixed at source, not documented around: `ReverseGeocoder::forTile()` takes an **H3 res-8
cell** and derives its centroid, so the network call carries a hexagon rather than a person. A
res-8 cell is ~0.74 km² — the city is the same, the doorstep is not. `LearnUnknownRegionTest`
asserts that the exact position never appears in the outbound request.

The general lesson is the one this record exists to enforce: **a new `Http::` host is a new
recipient, and a new recipient is an Art. 30 obligation before it is a feature.** Nominatim's
own usage policy also caps unauthenticated use at ~1 req/s and forbids bulk querying — a second
reason the hot path should be self-hosted (DATA-SOURCES §14).

## 7. Retention (Art. 30(1)(f))

Numbers live in `config/privacy.php` and are enforced nightly by `EnforceRetentionJob`
(`routes/console.php` — `dailyAt('03:30')`, the only scheduled task in the repo).

| Data | Retention | Then what | Enforced by |
|---|---|---|---|
| Raw precise location (`context_events.location`, session `origin` / `destination_point`, `trips.anchor_point`) | **30 days** | Coarsened to H3 res-8; **the coordinate is hard-deleted** | `CoarsenExpiredLocations` |
| Decision traces (`recommendations`) | Indefinite | At 30 days: lat/lng stripped out of `score_inputs` **and `anchor` nulled**; the H3 cells (`candidate.h3_index`, `anchor_h3_index`) and the scores remain | `CoarsenExpiredTraces` |
| Traces — **research-consent accounts only** | Indefinite, **full precision** | Nothing. That is what the consent buys. | The `NOT users.research_consent` predicate |
| Taste profile | Until reset, consent withdrawal, or account deletion | Deleted | `ResetTasteProfile`, `SetProfilingConsent::withdraw` |
| Calibration answers (`profile_signals`) | Until account deletion | Deleted | FK cascade |
| Feedback ledger | Until account deletion | Deleted | FK cascade |
| Account + identity | Until the user deletes it | Deleted | `DeleteAccount` |
| **Spend records (`cost_events`)** | **90 days** identified; the de-identified row is kept **24 months** for accounting | `user_id`, the correlation ids and `h3_cell` are **nulled**; the money, the timestamp and the vendor stay. **The row is not deleted on erasure — it is de-identified**, immediately. | `DeidentifyCostEvents` (nightly, and on erasure) |
| Google opening hours | **600 seconds**, in Redis | Evicted. **Never persisted** to any table — only the `place_id` string is stored. | `GoogleHoursVerifier` |
| **Telemetry (`pulse_*`)** | **7 days** (`PULSE_STORAGE_KEEP`) | Trimmed by Pulse itself. **Not** touched by our retention job, and **not** deleted on erasure. | Pulse's own trim — see §7.2 |
| **Admin audit (`activity_log`)** | **No limit. Not deleted on erasure** (it uses `causer_id` / `subject_id`, not `user_id`). | — | **Nothing. See §7.2.** |

### 7.1 Correction: Open-Meteo receives raw coordinates, not a tile

`WeatherClient::forTile()` caches the *response* per H3 tile, but the request it makes is
`?latitude={$lat}&longitude={$lng}` with **the session origin, at full precision**
(`app/Domain/Context/Services/WeatherClient.php:61-66` and `:101-107`; the caller passes
`$session->origin->lat/lng` from `RankSession.php:168`).

**DPIA §2.4 says "the coordinates of an H3 tile". That is not what the code does.** The
transfer is intra-EEA so there is no Ch. V problem, but it contradicts the data
minimisation claim in DPIA §4.1, and it is the direct cause of §7.2. The fix is one line —
send the tile's centroid, which is what the cache key already implies and what a weather
lookup actually needs.

### 7.1b Erasure de-identifies the cost ledger; it does not delete it (E24)

Every other user-scoped table in this schema cascades from `users`, and `DeleteAccount` is short
*because* of it. `cost_events` deliberately has **no foreign key to `users`** — and that decision
needs to be visible here, because a missing FK that is a decision looks exactly like a missing FK
that is a bug (which is what finding B7 was).

The reasoning: a cascade would let an erasure request delete the *accounting*. "Forget me" is not
a negotiation, but neither is it a licence to rewrite a month of spend out of the books. So
erasure **nulls** `user_id`, the correlation ids and `h3_cell`, and leaves the money. Afterwards
the row still says *"$0.0006 of flash-lite was spent at 14:32 on the 3rd"*; it no longer says by
whom, on which trip, or near where.

Two things make this safe rather than a loophole:

- The table **has** a `user_id` column, so the erasure test's `information_schema` enumeration
  *does* see it — the blindness that caused B7 does not recur. The test asserts the column is
  nulled and that the money survives.
- The same de-identification runs on a **90-day schedule** for everyone, erasure request or not,
  so the identified window is bounded by policy and not by whether anyone asks.

One live bug this surfaced, worth recording because it is the kind that would never have been
noticed: the request that *performs* an erasure is itself a metered request, and its cost row is
written by a terminating middleware — i.e. **after** the account is gone. The first implementation
therefore nulled every historical row and then wrote a fresh one carrying the deleted user's id.
The ledger resurrected the person it had just erased, on every single deletion. `DeleteAccount`
now clears the in-flight meter; `ExportAndErasureTest` asserts it.

### 7.2 The telemetry hole — **FIXED 2026-07-12**, and a correction to this record

**First, a correction to v0.1 of this document, which said Pulse had "no limit configured".
That was wrong.** Pulse trims itself to **7 days** (`PULSE_STORAGE_KEEP`). The exposure was
bounded, not indefinite, and this record overstated it. Getting that wrong in the direction
of alarm is better than the other direction, but it is still getting it wrong.

**What was nevertheless real.** The `SlowOutgoingRequests` recorder writes the **full
outbound URL** into `pulse_entries.key`, and Open-Meteo is a GET carrying the user's precise
position in its query string (§7.1). So a weather call that happened to run slow wrote *where
a person was standing* into a diagnostics table. The `ignore` and `groups` lists in
`config/pulse.php` were both empty (all commented out).

The 7-day trim means this was not a retention breach — 7 days sits inside the 30-day window.
**The serious part is the home zone.** DPIA §5.1 promises that inside it a coordinate is
*never written* — "not for thirty days, not for thirty seconds." A slow weather lookup near
someone's home broke that promise, in a table nobody thinks of as storage. That is the one
claim in this system that cannot be retrofitted, and telemetry was quietly falsifying it.

**Fix:** a `groups` rule on the recorder strips the query string from every recorded URL, so
the dashboard still reports *"Open-Meteo is slow"* — the entire reason the recorder exists —
while the thing that identifies a person never reaches the row. It is generic on purpose: the
next GET anyone adds with a coordinate, an email or a token in its query is covered without
anyone having to remember. Pinned by `tests/Feature/Privacy/TelemetryLeakTest.php`, because a
commented-out privacy control looks exactly like a commented-out example.

The root cause is still B3 (Open-Meteo should receive a tile centroid, not a person). Fixing
that removes the coordinate from the URL entirely; this fix means it would not leak even if
it were there.

**Still open — `activity_log`.** Admin role changes, keyed by `causer_id` / `subject_id`.
Survives the deletion of both users. Tracked as **B7**.

**Why the erasure test does not catch either.** `ExportAndErasureTest` enumerates
`information_schema.columns WHERE column_name = 'user_id'`. That is a good test and it is
honestly described — but Pulse stores the user id inside a string `key` column and
`activity_log` uses a morph, so **neither table has a `user_id` column and neither is in
the test's enumeration.** DPIA §5.4's claim is true as literally written and narrower than
it sounds.

---

## 8. Security measures (Art. 30(1)(g))

A general description, as the article asks. The controls are evidenced in DPIA §5, each
with a test.

- **Data minimisation as architecture.** Location is collected only in the foreground and
  only while a session is open — no background location, no geofencing, no passive
  tracking (enforced by phasing, PRD §8). Precise coordinates are destroyed at 30 days.
- **The declared home zone.** Inside it, nothing is learned, nothing is served, and the
  coordinate is **never written** — not for thirty days, not for thirty seconds
  (`HomeZone.php`). This is the only control that cannot be retrofitted: *"we delete it on
  schedule"* and *"we never had it"* are different promises, and only one survives a breach.
- **Pseudonymisation at the boundary.** No user identifier is placed in any outbound
  payload, anywhere in `app/`. Vendors receive coordinates or place evidence, never a person.
- **Purpose separation in the schema.** Google-derived data is never persisted to a
  world-model table (ODbL + Google ToS); a test dumps the tables and asserts it.
- **Encryption in transit.** TLS terminated at Traefik; the app trusts the proxy explicitly.
- **Access control.** Registration allowlisted pre-launch. Role-gated admin console.
  Passwords hashed by the framework default. Export and erasure require password
  confirmation.
- **Erasure and portability as code, with tests** — `DeleteAccount`, `ExportUserData`.
- **Encryption at rest: OPEN.** Not verified. → §9.
- **Backups: OPEN.** Not documented, and a backup that outlives the 30-day retention clock
  quietly defeats it. → §9.

---

## 9. Open items this record surfaces

Ranked. These are additions to DPIA §7, not a restatement of it.

| # | Item | Why it matters | Severity | Status |
|---|---|---|---|---|
| **B1** | Pulse recorded precise coordinates from the Open-Meteo URL (§7.2) | Falsified the home-zone "never written" promise — the one control that cannot be retrofitted. | Blocker | **FIXED** — query strings stripped; `TelemetryLeakTest` |
| **B2** | Confirm Gemini API input is not used for training (§6.2) | If it were, we would be processing users' evidence for a vendor's purposes with no basis and no disclosure. | Blocker | **CLOSED 2026-07-12 — paid tier, confirmed by the controller.** Google does not train on paid-tier API input. Re-check on any billing change. |
| **B3** | Open-Meteo receives raw coordinates, not a tile centroid (§7.1) | Contradicts DPIA §2.4 and §4.1, and is B1's root cause. | High | **In progress** (WeatherClient centroid) |
| **B4** | No Terms of Service existed (§3) | Art. 6(1)(b) is the basis for P0/P1/P3 and it requires a contract. | High | **FIXED** — `/terms-of-service` |
| **B5** | No processor DPA filed (Art. 28) | → [`PROCESSORS.md`](PROCESSORS.md), [`dpa/`](dpa/). Writing the register does not close this; signing does. | High | **OPEN — yours** |
| **B6** | `context_events.companions` may hold third-party personal data (§4.4) | Constrain to an enum. Cheap, and almost certainly the intent. | Medium | OPEN |
| **B7** | `activity_log` and `pulse_entries` survived account deletion (§7.2) | An erasure gap. Neither has a `user_id` column, so no FK cascaded and the erasure test's schema enumeration could not see them. | Medium | **FIXED** — `DeleteAccount` clears both ends of the `activity_log` morph and Pulse's per-user rows; asserted explicitly in `ExportAndErasureTest` |
| **B8** | Encryption at rest and backup retention unverified (§8) | A backup outliving the retention clock silently defeats it. And Art. 34(3)(a) — encryption is the difference between emailing your users about a breach and logging it. | Medium | OPEN |
| **B9** | No age assurance (§2) | Only defensible while registration is allowlisted. | Medium (Low today) | OPEN |
| **B10** | **No breach detection at all** (BREACH-PROCEDURE §8) | The 72-hour clock starts when you *notice*. Nothing pages anyone. | Medium–High | OPEN |
| **B11** | Reverse geocoding sent the user's precise origin to Nominatim (§6.1) | B3 repeated in a new host: a real coordinate to a third party, for an answer a tile centroid gives just as well. Introduced and caught the same day (E48). | High | **FIXED** — `ReverseGeocoder::forTile()` sends an H3 res-8 centroid; asserted in `LearnUnknownRegionTest` |

---

## 10. Keeping this true

This record goes stale silently. Two cheap tripwires, both of which belong in CI:

1. **A test that fails when a new table gets a `user_id`** (or any geography column) and
   is not listed in §4. The erasure test already enumerates the schema — extend it to
   assert the set matches an expected list, so *adding* a table is what breaks the build.
2. **A test that fails when a new outbound host appears.** Every outbound call goes through
   `Http`; a test that asserts the set of hosts equals the set in §6 turns "we added a
   vendor and forgot the ROPA" into a red build.

Both convert "remember to update the ROPA" — which nobody does — into "the build is red",
which everybody fixes.

---

## 11. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-12 | 0.1 | First record. Written from the schema and the outbound-call inventory, not from the DPIA — which is why it disagrees with DPIA §2.4 in two places (§7.1). | Claude (Opus 4.8), for the controller |

**Next review:** on any migration adding personal data, any new outbound recipient, any
change to `config/privacy.php`, or the first user outside the pilot — whichever is first.

**Not reviewed by a lawyer.** §4, §6 and §7 are grounded in the code and should be treated
as reliable statements of *what the system does*. §3 (lawful bases) and §5 (Art. 9) are
legal judgements, and are exactly what a human reviewer should look at first.
