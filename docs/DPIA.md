# DPIA — Data Protection Impact Assessment

**Travel Companion AI** · GDPR Article 35
**Status: DRAFT — not yet reviewed. Must be reviewed before any user outside the pilot group.**
**Version:** 0.2 (2026-07-14) — **rev 2 adds Trip Mode (background location) and push delivery**, the two
processing operations Phase 2 introduces and the two that change this document's risk picture most.
· Policy versions: `config/privacy.php` → `v1` · `NotificationPolicy::VERSION` → `v1`

---

## 0. How to read this document

This is an assessment, not a marketing page. Where the answer is uncomfortable it says so, and
where something is **not yet true** it is marked **OPEN** rather than written in the past tense.
A DPIA that describes intentions as if they were controls is worse than no DPIA, because it
launders the risk.

Everything in §5 (Measures) is **running code with tests**, not a plan. The test file is cited so a
reviewer can check the claim rather than take it. If a control is only planned, it is in §7
(Residual risk), not §5.

---

## 1. Is a DPIA required?

**Yes — and it would be prudent even if it were arguable.**

Article 35(3) lists three automatic triggers. We do not cleanly hit any of them:

- **35(3)(a)** — automated decisions producing *legal or similarly significant effects*. We do not.
  A recommendation to walk to a viewpoint is not a legal effect, and Art. 22 is **not** engaged
  (see §4.4).
- **35(3)(b)** — large-scale processing of Article 9 special-category data. Not *intentionally*
  (but see §3.2 — this is the sharpest risk in the document).
- **35(3)(c)** — large-scale systematic monitoring of a public area. We monitor the user, not the
  area, and "large scale" does not describe three people.

But Art. 35(1) is the actual test — *likely to result in a high risk* — and the EDPB's nine
criteria (WP248) say two or more is a strong indicator. We hit at least four:

1. **Evaluation / scoring** — we score places against an inferred model of a person's taste.
2. **Systematic monitoring** — precise location, continuously, while a session is open.
3. **Sensitive data or data of a highly personal nature** — location is highly personal, and can
   *infer* special-category data (§3.2).
4. **Innovative use of technology** — LLM-generated text over an evidence chain, and a learned
   preference profile.

**Conclusion:** a DPIA is required, or close enough to required that not doing one would itself be
the risk. PRD §16 reached the same conclusion independently.

---

## 2. The processing, described systematically (Art. 35(7)(a))

### 2.1 Controller

**Mats Bergsten**, acting as a natural person (no company yet).
Contact: `rockstoneaidev@gmail.com`.

> **This matters more than it looks.** There is no corporate veil here. As controller you are
> personally the accountable party under GDPR, and personally exposed to Art. 83 fines and to civil
> claims under Art. 82. The **household exemption (Art. 2(2)(c)) does not apply** — the moment there
> is a second user who is not you, this is not "purely personal or household activity". With three
> users it is a product with a controller, not a hobby.
>
> **OPEN (for the legal reviewer):** whether to incorporate before the pilot grows. This is a
> business decision with a data-protection consequence, not the other way round.

**DPO:** none, and **none is required** — Art. 37 triggers on public authorities, or *large-scale*
regular systematic monitoring, or *large-scale* Art. 9 processing. Three pilot users is not large
scale. **This changes if the pilot grows** (§7.4).

### 2.2 Purposes

| # | Purpose | Why the data is needed for it |
|---|---|---|
| P1 | Recommend nearby opportunities worth the user's time | Requires current location and a time budget |
| P2 | Personalise those recommendations | Requires a preference profile, learned from feedback |
| P3 | Explain a recommendation ("why did I get this?") | Requires retaining the decision trace |
| P4 | Improve the ranking model | Requires replaying past decisions against gold traces |
| **P5** | **Speak first — interrupt the user when something nearby is worth it and about to stop being available** (Phase 2, "Trip Mode") | Requires **location while the app is not open**, and a push token |

**P5 is the purpose this revision exists for, and it is a different kind of purpose from P1–P4.**
P1 answers a question the user asked. P5 decides, on the user's behalf, that a moment is worth their
attention — and it does so from data collected while they were not looking at their phone. That is
the processing a regulator would look at first, and it should be.

It is also the only purpose that is **entirely optional**, in the strict sense that the product is
complete without it. That is what makes consent available as a basis (§4.2), and it is why the
controls in §5.9 are structured as *refusals to collect* rather than as promises about use.

P4 is the only one that is not strictly necessary to deliver the service to *that* user, which is
why it is the one gated behind separate opt-in consent (§4.2).

### 2.3 Categories of personal data

| Category | Examples | Where | Special category? |
|---|---|---|---|
| Identity | name, email, password hash | `users` | No |
| Precise location | session origin, context-event pings, destination | `explore_sessions`, `context_events`, `trips` | No, but see §3.2 |
| Coarse location | H3 res-8 cell (~0.7 km²) | same tables, after coarsening | No |
| Inferred preferences | facet weights, walk tolerance, price band | `user_taste_profiles` | **Potentially — see §3.2** |
| Behavioural | accepted / kept / dismissed / **visited** | `recommendation_feedback` | No, but see §3.2 |
| Decision traces | every sub-score and input behind a recommendation | `recommendations` | No |
| **Background location** (Trip Mode only) | position pings recorded **while the app is closed**, during an opted-in trip | `context_events` (`context_source`) | No, but **see §3.4** |
| **Device / push token** | FCM registration token, platform, last-seen | `devices` | No |
| **Interruption record** | every push we sent **and every one we decided not to send**, with the gate that stopped it | `notifications` | No |

The third row deserves a note, because a naive reading makes it look like over-collection. We store
the **denials** as well as the sends (`notifications.allowed = false`, `denied_by`). That is more
data about a person, not less, and it is deliberate: PRD §12.2 requires being able to ask, offline,
*"would policy v3 have avoided the push that policy v2 sent?"* — a question only answerable if the
refusals are on record. A system that stores only its interruptions cannot prove its restraint.

### 2.4 Recipients and processors

| Recipient | What it receives | Basis for the transfer |
|---|---|---|
| **Hetzner / hosting (Germany)** | everything (it is the database) | EU/EEA — no transfer |
| **Google Places** (`places.googleapis.com`) | a place's name + coordinates, to verify opening hours | US — see §6 |
| **Google Routes** (`routes.googleapis.com`) | **the user's origin coordinates** + a destination | US — see §6 |
| **Google Gemini** (LLM) | place evidence + `part_of_day`, `travel_mode`, `walk_minutes`, `city_name` | US — see §6 |
| **Google OAuth** (sign-in) | the sign-in itself; returns `sub`, email, name, avatar | US — **independent controller**, not a processor |
| **Open-Meteo** (`api.open-meteo.com`) | **the user's origin coordinates, at full precision** — *not* a tile centroid; see the correction below | EU (Germany) |
| **Resend** (transactional email) | **the user's email address** + message body | US — see §6 |
| **Google FCM** (`fcm.googleapis.com`) — *Phase 2* | a **device push token** + the notification title/body/deep-link. **No coordinates, no user id, no place id.** | US — see §6, and **§7.5: there is no DPA for this yet** |
| **Overpass / OpenStreetMap, Wikidata, Wikimedia, DATAtourisme, Mérimée** | region bounding boxes only — **no user data at all** | n/a |

> **CORRECTION (2026-07-12).** Version 0.1 of this table said Open-Meteo receives "the
> coordinates of an H3 tile", and omitted Resend and Google OAuth entirely. Both were wrong,
> and both were found by building the Art. 30 record from the **code** rather than from this
> document — see [`legal/ROPA.md`](legal/ROPA.md) §7.1.
>
> `WeatherClient::forTile()` caches the *response* per H3 tile, but the request it makes is
> `?latitude={$lat}&longitude={$lng}` with the session origin at full precision. The cache key
> is the tile; the payload is the person. The transfer is intra-EEA so there is no Ch. V
> problem, but it contradicts the minimisation claim in §4.1, and it is the direct cause of the
> telemetry leak recorded in ROPA §7.2. **Fix in progress: send the tile centroid.**
>
> This is exactly the failure this document warns about in §0 — a DPIA that describes intentions
> as if they were controls. It described one, and it took an audit of the code to catch it.

**Two honest notes on this table.**

**Google Routes receives the user's actual position.** That is the sharpest transfer in the system:
we send *where a person is standing right now* to a US company. It is unavoidable if we want a real
walking time (PRD §10 Stage B), and it is mitigated (no user identifier travels with it — see §5.6),
but it should not be soft-pedalled.

**Gemini receives no identity and no profile.** Verified in code: the evidence bundle carries place
evidence plus a four-field context object (`app/Domain/Agent/Data/ContextData.php` — part of day,
travel mode, walk minutes, city name). No user id, no email, no coordinates, no taste vector. This
is a deliberate boundary, not an accident, and it is worth keeping: it means the LLM vendor cannot
build a profile of our users even in principle.

### 2.5 Retention

Numbers, not adjectives — they live in `config/privacy.php` and are enforced nightly by
`EnforceRetentionJob`.

| Data | Retention | Then what |
|---|---|---|
| Raw precise location | **30 days** | Coarsened to H3 res-8; the coordinate is **hard-deleted** |
| Decision traces | indefinite | Their lat/lng are stripped at 30 days (H3 cell remains) |
| Traces (research-consent accounts) | indefinite, **full precision** | Nothing — that is what the consent buys |
| Taste profile | until reset or account deletion | Deleted |
| Feedback ledger | until account deletion | Deleted |
| **Background pings (Trip Mode)** | **the same 30 days as any other location** — Trip Mode buys no retention extension | Coarsened to H3 res-8; coordinate hard-deleted |
| **Push tokens** | until the device is revoked or the account deleted | Deleted |
| **Notification decisions** | until account deletion | Deleted with the account |

---

## 3. Risks to the rights and freedoms of data subjects (Art. 35(7)(c))

### 3.1 The general shape of it

A record of where a person went, when, and what they chose to do there, is one of the most
revealing datasets it is possible to hold about someone — more revealing than their browsing
history, because it is their actual body in actual space. The harms are not abstract:

- **Re-identification.** Four spatio-temporal points are enough to uniquely identify 95% of people
  in a mobility dataset (de Montjoye et al., 2013). Pseudonymisation does almost nothing against
  this. Our defence is *not keeping the points*, which is the only defence that works.
- **Inference of the home address.** A location trace's most-frequent overnight cluster is the
  user's home, and it falls out of the data almost for free.
- **Exposure via breach.** The controller is one person with no security team.

### 3.2 The sharpest risk: Article 9 by inference

**This is the finding a reviewer should look at first.**

We do not *ask* for special-category data. But our own taxonomy (`docs/TAXONOMY.md`) contains a
`religious_sacred` place-type domain and a `spiritual` appeal facet, and the taste profile learns a
weight for exactly those. A user who repeatedly accepts and visits churches, mosques or synagogues
will, by design, accumulate a high `spiritual` / `religious_sacred` weight.

**That vector is an inferred statement about a person's religious belief**, which is Article 9(1)
data. The CJEU has held (C-184/20, *OT v Vyriausybinė*) that data from which special-category data
can be **indirectly deduced** falls under Art. 9. The same logic reaches:

- **Health** — a repeated pattern around a hospital or clinic.
- **Sexual orientation** — venue types are not in our taxonomy today, but the mechanism is generic.

We do not currently have a lawful basis under Art. 9(2) for this. The only realistic one is
**explicit consent** (Art. 9(2)(a)).

**OPEN — REQUIRES A DECISION BEFORE LAUNCH.** Three options, and I recommend the first:

1. **Explicit consent for the taste profile**, presented at calibration (S9), naming plainly that
   the profile can reflect religious or other sensitive interests. This is honest, it is cheap
   (the calibration screen already exists and already asks), and it makes the profiling lawful.
2. Exclude `religious_sacred` / `spiritual` from the learned profile. This damages the product for
   an obvious and legitimate category of traveller, and does not solve the general inference
   problem.
3. Argue the inference is too weak to constitute Art. 9 data. I do not think this survives
   *OT v Vyriausybinė*, and I would not want to defend it.

### 3.3 Risk register

| # | Risk | Likelihood | Severity | Net |
|---|---|---|---|---|
| R1 | Re-identification from a location trace | Medium | High | **High** |
| R2 | Home address inferred from pings | High (trivially) | High | **High** |
| R3 | **Art. 9 data inferred into the taste profile** (§3.2) | **High — by design** | High | **High** |
| R4 | Precise location sent to Google (US) | Certain (it is the design) | Medium | **Medium** |
| R5 | Breach — sole controller, no security team | Low–Medium | High | **Medium–High** |
| R6 | Function creep (data kept "just in case") | Medium | Medium | Medium |
| R7 | Consent that is not freely given (3 friends of the founder) | **High** | Low–Medium | Medium |
| R8 | **Background location — a continuous record of where a person went, collected while they were not looking** (§3.4) | **High — by design, when Trip Mode is on** | High | **High** |
| R9 | **Interruption itself as a harm** — being spoken to at the wrong moment (§3.4) | Medium | Low–Medium | Medium |
| R10 | Push token + notification content held by Google, with **no DPA** (§7.5) | Certain | Low–Medium | **Medium** |

R7 deserves a word. Consent must be **freely given** (Art. 4(11)), and a pilot group of three people
who know the founder personally is close to the definition of social pressure. Mitigation: the pilot
must be able to leave, delete everything, and refuse research consent **without it being awkward** —
which is a design and a social problem, not just a legal one.

---

### 3.4 The second-sharpest risk: being followed, and being spoken to

§3.2 is about what the profile can *infer*. This is about what the trace can *see* — and Trip Mode is
the feature that changes the answer.

**Foreground location is a set of moments the user chose.** They opened an app and asked a question,
and the answer required knowing where they stood. Each ping is an act of asking.

**Background location is not moments. It is a line.** Turn it on for a week in Burgundy and the
`context_events` rows are a reconstruction of a person's week: where they slept, how long they
lingered, who they might have been near, when they stopped moving and for how long. Nobody
volunteered that. They agreed to a *feature*, and the line is a by-product.

The honest statement of the risk is therefore not "we collect location" — we already did — it is:

> **In Trip Mode, the by-product of the feature is a movement history detailed enough to answer
> questions the user never asked and would not have agreed to.**

Three specific ways it bites, and what each one is actually met with (all in §5.9, all running code):

1. **The home address falls out of the data for free** (R2, now much worse). A background trace
   overnight *is* a home address. → The home zone is not merely excluded from serving; events inside
   it are **dropped before they are written** — no coordinate, no cell, no row.
2. **Density becomes surveillance.** A ping every 30 seconds is a different dataset from a ping when
   you arrive somewhere, even though both are "location". → The server enforces a
   **meaningful-movement floor** and discards anything below it, so a chattier client release cannot
   quietly turn a companion into a tracker.
3. **The dwell time is the sensitive part.** *Which* place is often innocuous; **how long you stayed
   at the clinic** is not. → The 30-day coarsening applies unchanged (Trip Mode buys no retention
   extension), and after it, res-8 cells at ~0.7 km² no longer distinguish a building from its
   neighbours.

**R9 — interruption as a harm.** This one has no GDPR article and is easy to leave out of a document
like this, which is exactly why it goes in. Being pushed at while driving is a *safety* risk, not a
privacy one; being pushed at three times an hour is not a rights violation but it is the product
failing at the only thing it promised. Art. 25 ("data protection by design") is the closest hook, and
the design answer is that **no model chooses the moment** — §5.9.

---

## 4. Necessity and proportionality (Art. 35(7)(b))

### 4.1 Data minimisation, concretely

- Location is collected **only while an explore session is open** and **only in the foreground**.
  There is no background location, no geofencing, no passive tracking (PRD §8, enforced by phasing).
- Precise coordinates are kept **30 days**, then destroyed. The H3 cell that survives is ~0.7 km² —
  enough to learn "this person likes waterfront viewpoints", not enough to find their door.
- The home zone (§5.1) means the most sensitive location in a person's life is **never stored
  precisely at all**, not even for a day.

### 4.2 Lawful basis

**Decided: consent (Art. 6(1)(a))** for the personalisation, and — pending §3.2 — **explicit
consent (Art. 9(2)(a))** for the profile insofar as it can infer special-category data.

Why consent and not legitimate interests: the personalisation *is* the product, and it is a
"systematic and extensive evaluation of personal aspects" that a user would not expect by default.
Trying to squeeze it into legitimate interests would be the kind of move that reads badly in a
regulator's summary.

Consequences we accept by choosing consent:

- It must be **as easy to withdraw as to give** (Art. 7(3)). It is: *Settings → Taste → Reset my
  taste profile*, one click, no password, no dark pattern.
- It must be **granular**. Research consent is separate, off by default, and refusing it does not
  degrade the service.
- The service must still function, in a degraded but honest way, for a user who refuses to be
  profiled. **It does** — that is exactly what the cold-start vector is (α = 0, SCORING §6).

~~**OPEN:** the consent *text* itself has not been written.~~ **CLOSED** — it is written, verbatim and
versioned, in [`legal/CONSENT.md`](legal/CONSENT.md) §2 (C1). Art. 7(1) requires demonstrating not
that consent was given but *what was agreed to*, which means the words themselves are the record.

#### Trip Mode and push: consent again, and this time it is uncontroversial

**Basis: Art. 6(1)(a) consent, per trip**, plus **ePrivacy Art. 5(3)** for the push itself. Wording
in [`legal/CONSENT.md`](legal/CONSENT.md) §2A (C3).

Note the contrast with foreground location, which is **Art. 6(1)(b)** — necessary for a contract the
user asked for — and deliberately *not* consent-based, because "consent" to the one thing the product
cannot work without would not be freely given (Art. 4(11)).

Trip Mode inverts every part of that test, which is why the answer inverts too:

| | Foreground location | Trip Mode |
|---|---|---|
| Can the product work without it? | No | **Yes, completely** |
| Would refusing it degrade the service the user asked for? | Fatally | Not at all — it *adds* a service |
| Is a "no" therefore free? | Not really | **Yes** |
| So the basis is | 6(1)(b) | **6(1)(a) consent** |

**Scoped to a trip, not to an account.** The consent lives in `trips.trip_mode_started_at`, so it
expires with the trip that motivated it. There is no global switch to leave on by accident, and
"follow me around Burgundy in August" cannot silently become "follow me around Stockholm in October".

**Withdrawal (Art. 7(3)) is one tap and takes effect at the next ping** — `StopTripMode` sets
`trip_mode_ended_at`, and `RecordTripContext` refuses (`trip_mode_off`) any event that arrives after
it, *including one already in flight from the handset*. The refusal is server-side on purpose: a
withdrawal that depended on the client honouring it would be a promise, not a control.

### 4.3 Purpose limitation

The feedback ledger is described internally as "the moat" and is deliberately never deleted short of
account deletion. That is a **commercial** motivation, and it must not be allowed to become a
retention justification on its own. It is lawful here because it is (a) necessary for P3
(explainability) and P4 (improvement), (b) covered by consent, and (c) destroyed on erasure. If any
of those three stop being true, the retention stops being lawful.

### 4.4 Automated decision-making (Art. 22)

**Art. 22 is not engaged.** The ranking produces a menu of suggestions with no legal or similarly
significant effect; the user is free to ignore all of it, and most of the time the correct output is
silence. Explainability is nevertheless built in (PRD §15, the decision trace, and the "why you"
line on every card) — not because Art. 22 compels it, but because a companion that cannot say why it
suggested something does not deserve to be trusted.

---

## 5. Measures already in place (Art. 35(7)(d))

Each is running code with a test. The citation is so a reviewer can check rather than believe.

### 5.1 Declared home zone — no learning, no serving, no precise storage
`app/Domain/Privacy/Services/HomeZone.php` · `tests/Feature/Privacy/HomeZoneTest.php`

Inside the zone, a context event keeps **only its H3 cell**; the coordinate is never written — not
for thirty days, not for thirty seconds. Suppression happens *before scoring*, so a suppressed place
leaves no trace even in the decision funnel (a funnel recording what is near your home is a record of
where you live).

This is the one control that cannot be retrofitted. The retention job can coarsen a coordinate
later; it cannot un-store one. *"We'll delete it on schedule"* and *"we never had it"* are different
promises, and only one of them survives a breach.

### 5.2 Retention, executed nightly
`app/Domain/Privacy/Actions/CoarsenExpiredLocations.php` · `tests/Feature/Privacy/RetentionTest.php`

Coarsen, don't erase: keep the cell and the derived signals (what the pipeline learns from), destroy
the coordinate (what identifies a doorway). **Hard**-deleted — not soft-deleted, not archived. Runs
on a schedule, so it does not depend on anyone remembering.

### 5.3 Trace coarsening, with a research exemption that is opt-in
`app/Domain/Privacy/Actions/CoarsenExpiredTraces.php`

Traces are kept indefinitely (they are how we answer "why did I get this?"), but their coordinates
are stripped on the same 30-day clock — **except** for accounts that explicitly opted in. That flag
is `false` by default and tested in both directions: consent that must be *given* is consent;
consent that must be *revoked* is not.

### 5.4 Erasure that actually erases (Art. 17)
`app/Domain/Privacy/Actions/DeleteAccount.php` · `tests/Feature/Privacy/ExportAndErasureTest.php`

The test enumerates **every table with a `user_id` from `information_schema`**, deletes an account,
and asserts each is empty — so it fails the day someone adds a table that forgets to cascade. The
feedback ledger goes too: it is the moat, and losing it hurts, but it is *their* moat, and "delete my
account" is not a negotiation.

### 5.5 Portability (Art. 20)
`app/Domain/Privacy/Actions/ExportUserData.php`

Everything, as a JSON file: trips, what we showed them and why, what they told us back, and **the
taste profile itself** — the thing that actually decides what they see. Location fields export as
they are *stored*, so a coarsened trace exports as a cell. The export tells the truth about what we
kept, including that we let it go.

### 5.6 Google is edge-only, and no identifier travels with the request
`app/Domain/Context/Services/GoogleHoursVerifier.php` · `tests/Feature/Context/GoogleHoursTest.php`

Google-derived data is **never persisted** into any world-model table — the only value we may store
is the `place_id` string. A test dumps `places_core`, `opportunities` and `place_source_ids` and
asserts no trace of opening hours is in any of them. Requests carry **no user id, no session id, no
cookie** — Google receives a coordinate pair, not a person.

### 5.7 The LLM is never a source of facts
`docs/conventions/10-llm-usage.md`

Generation happens only from stored evidence, every generation records its `prompt_version`, and no
user identity or profile is ever sent (§2.4). A factual claim on a card ("~40 min of light left") is
*computed* (`SunClock`), never generated.

### 5.9 Trip Mode: four refusals, and a policy that is not a model

Everything here is running code with tests
(`tests/Feature/Context/TripModeTest.php`, `tests/Feature/Notifications/NotificationPolicyTest.php`,
`tests/Feature/Notifications/PushDeliveryTest.php`). Each control is a **refusal to collect or to
act**, enforced on the server — never a promise about what we will later do with what we took.

**(a) No consent, no data.** `RecordTripContext` refuses (`trip_mode_off`) every background event for
a trip whose `trip_mode_started_at` is null or whose `trip_mode_ended_at` has passed. The client
cannot override it, and an event already in flight when the user withdraws is refused on arrival. This
is what makes Art. 7(3) withdrawal a control rather than a courtesy.

**(b) The home zone is not written down.** Inside a declared home zone, a background event is
**discarded entirely** — the row is never created. Stricter than the foreground path (which stores a
coarsened cell), and deliberately so: a trace that shows *nothing at all* between 22:00 and 07:00 is
the only trace that does not contain a home address. Directly answers R2 and §3.4(1).

**(c) A meaningful-movement floor, on the server.** An event closer than
`min_distance_meters` (250) to the last one **and** sooner than `min_interval_seconds` (600) is
refused as `not_meaningful` and never stored. *Far enough OR long enough* — never both, so a
stationary user is still recorded occasionally and a walking one is not recorded constantly. The floor
is server-side precisely so that a future client release cannot widen the collection without a server
change that shows up in review.

**(d) Deterministic policy, and no model gets a vote.**
`NotificationPolicy` is pure PHP with no I/O (non-negotiable #4). Eleven hard gates — Trip Mode off,
quiet hours (22:00–08:00, wrapping midnight), **driving**, not pushable by licence, low confidence,
not open, detour too far, stale evidence, category recently rejected — then a **hard cap of 3 pushes
a day** and a 60-minute cooldown. The "urgent" exception (confidence > .85 ∧ urgency > .85 ∧ fit >
.75) buys the right to skip the **cooldown** and nothing else: it cannot buy the daily cap, it cannot
buy quiet hours, and it certainly cannot buy driving.

An LLM may write the *words* of a push. It may never choose the *moment*. That is the difference
between a decision that can be explained to a data subject and one that can only be apologised for —
and it is why R9 is met by an `if` statement rather than by a prompt.

**(e) The push carries a token and a sentence.** `FcmPushSender` sends the device token, a title, a
body and a deep link. **No coordinates, no user id, no place id, no profile.** Google learns that a
handset was pinged and what it was told; it does not learn where the handset was or who owns it. The
remaining exposure is R10 — the missing DPA (§7.5), not the payload.

### 5.8 Security
- TLS terminated at the proxy; the app trusts it explicitly (`bootstrap/app.php`).
- Registration allowlisted while pre-launch (`ALLOWED_REGISTRATION_EMAILS`).
- Passwords hashed by the framework default; erasure and export require password confirmation.
- Role-gated admin console with position-emulation (`docs/ADMIN.md`).

---

## 6. International transfers (Ch. V)

Hosting is in **Germany** — no transfer.

The three Google services (Places, Routes, Gemini) are provided by **Google LLC / Google Ireland**.
Google is certified under the **EU–US Data Privacy Framework**, which is an adequacy decision
(Art. 45), so a transfer to a DPF-certified recipient is lawful without SCCs. Google's Cloud/Maps
terms also incorporate SCCs as a fallback.

**FCM (Phase 2)** is the same story with one difference that matters: the payload is minimal (a token
and a sentence — §5.9(e)), but **there is no DPA covering it**, and Firebase's terms are not the Cloud
terms or the Maps terms. See §7.5; this is an errand, not a design problem.

**OPEN — for the legal reviewer:**
- Confirm the *specific* Google entity and terms we contract with, and that they cover the DPF or
  SCCs for **Maps Platform** and **Gemini API** specifically (they are different products with
  different terms, and it is a mistake to assume one covers the other).
- ~~Confirm whether Gemini API input is used for training under the terms we accept.~~
  **CLOSED 2026-07-12 — we are on the paid tier**, where Google does not train on API input.
  On the free tier it generally does, and that would have been a blocker: even sending no
  identity, place evidence + city + time would have been processing handed to the vendor for
  *its* purposes rather than ours. **Note what this leaves us depending on** — the lawfulness
  of the LLM pipeline now rests on a billing status, and a key that quietly falls off billing
  would make the processing unlawful with nothing in the code noticing.
- The DPF is under active legal challenge (*Latombe*). If it falls, we need SCCs + a transfer impact
  assessment. Plan for that rather than be surprised by it.

---

## 7. Residual risk and what is NOT yet done

Being honest here is the whole point of the exercise.

### 7.1 ~~OPEN~~ IMPLEMENTED — Art. 9 consent (§3.2)
The taste profile can infer religious belief, so explicit consent is now required before any weight
moves. The gate lives in `FacetWeightLearner` — the one place a facet weight can change — so a caller
added later cannot bypass it (`tests/Feature/Privacy/ProfilingConsentTest.php`).

- **Explicit and separate**: an unticked box on the calibration welcome screen, naming plainly that
  the profile can reflect personal things such as an interest in religious sites. Not a side effect
  of pressing "start".
- **Asked once.** `profiling_consent_asked_at` is a distinct fact from consent itself, because
  without it there is no way to stop asking — and a choice you are shown until you pick the right
  answer is not freely given (Art. 4(11)). Declining is final; the user can opt in later from
  Settings → Privacy, on their own initiative.
- **Withdrawal deletes the profile**, not merely the learning: holding a vector from which religious
  belief can be deduced is itself processing, and without consent there is no basis for it.
- **Refusal costs personalisation, not the product** — α stays 0 and the honest cold-start ranking
  applies (SCORING §6).

**Still OPEN:** the consent wording has not been reviewed by a **human** lawyer. It has now been
reviewed against Arts. 4(11), 7 and 9(2)(a) element by element in **[legal/CONSENT.md](legal/CONSENT.md)**
§2.2, which finds that it holds — and which archives the exact text, because Art. 7(1) requires the
controller to be able to demonstrate *what was agreed to*, and a timestamp proves only that someone
clicked. Two changes came out of that review and are implemented: the disclosure now names the
*mechanism* (health as well as religion) rather than one instance of it, and the screen now links to
the privacy notice — which is what turns "informed about the profiling" into "informed" (Art. 13(1)(e)).

### 7.2 ~~OPEN~~ DONE — consent text and a privacy notice (Arts. 13–14)

Both now exist.

- **[legal/CONSENT.md](legal/CONSENT.md)** — the consent register: every consent we ask for, its exact
  on-screen wording, its version, where the record is stored, and how it is withdrawn. Versioned text
  is archived, so widening what the profile infers invalidates the old agreement rather than silently
  stretching it.
- **[legal/PRIVACY-NOTICE.md](legal/PRIVACY-NOTICE.md)** — the Art. 13–14 notice, shipped as a public
  page at **`/privacy-policy`**, alongside **`/terms-of-service`**. Both sit *outside* the auth group
  on purpose: Art. 13 wants the notice available "at the time when personal data are obtained", and a
  notice you can only read once you already have an account arrives after the decision it exists to
  inform. The sign-up form and the consent screen both link to it.

The retention figure on the notice is read from `config/privacy.php`, not typed into the page — a
notice that says "30 days" while the job enforces 60 is a false statement to a data subject.

**The Terms of Service is not decorative.** Six rows of the notice rely on Art. 6(1)(b) — performance
of a contract — as the basis for processing location, and that basis requires a contract to exist.
None did. This was ROPA finding B4.

### 7.3 ~~OPEN~~ DONE — Records of processing (Art. 30)
The <250-person exemption does **not** apply: our processing is not occasional, and (per §3.2) may
involve Art. 9 data. A ROPA is therefore required, and one now exists: **[legal/ROPA.md](legal/ROPA.md)**.

It must be kept in step with §2 of this document and with `config/privacy.php`. If the categories of
data, the processors, or the retention numbers change and the ROPA does not, the ROPA is worse than
nothing — a record of processing that no longer records the processing.

### 7.4 ~~OPEN~~ WRITTEN, NOT REHEARSED — breach procedure (Arts. 33–34)

**[legal/BREACH-PROCEDURE.md](legal/BREACH-PROCEDURE.md)** now exists: the first hour, the
notify/don't-notify decision, a risk table for *this* system's tables (almost anything with a
`user_id` is high risk here, because the payload is location and inferred belief), templates for IMY
and for users, and the Art. 33(5) internal log that is required even for breaches you decide **not**
to report.

The supervisory authority is **IMY (Sweden)** — the controller's establishment, not the servers'.
Hetzner being in Germany does not move it (Art. 56).

**Still OPEN, and honestly the weakest control in the system: nothing would tell you.** Detection
today depends entirely on you looking — no alerting, nothing pages anyone. If the database were being
dumped over a weekend, the 72-hour clock would not start until you happened to notice, which is
legally in your favour and practically catastrophic. §8 of the procedure lists the cheapest fixes;
the first is an afternoon's work.

**Rehearse it once before the pilot.** A procedure discovered during an incident is not a procedure.

### 7.5 OPEN — processor agreements (Art. 28) · **STILL OPEN, AND ONLY YOU CAN CLOSE IT**

**[legal/PROCESSORS.md](legal/PROCESSORS.md)** is the register — derived from the code by enumerating
every outbound call, which is how it found **Resend**, a processor holding every user's email address
that this document had never mentioned.

**Writing the register does not close this. Signing does.** Five errands, none delegable to any LLM,
tracked in [legal/dpa/](legal/dpa/): Hetzner AVV, Resend DPA, confirming the Google Cloud DPA actually
covers **Maps Platform** (a different product, and assuming one DPA covers both is the trap),
**adding Firebase Cloud Messaging to that same question** (a *third* Google product with *third* terms —
ROPA B13, and the reason R10 exists), and filing the PDFs — because Art. 5(2) accountability means being able to *demonstrate* compliance, and
"I'm sure I clicked accept" is not a demonstration.

**The Gemini tier question is CLOSED (2026-07-12): we are on the paid tier**, where Google does not
train on API input. On a free key it generally does, which would have made place evidence + city +
part-of-day into processing for *Google's* purposes rather than ours — a processor acting outside the
controller's instructions (Art. 28(3)(a)), for a purpose disclosed to nobody.

What remains is **five errands and a filing cabinet**, and they are the whole of what stands between
this and a defensible launch.

### 7.6 Accepted residual risk
With §7.1–§7.5 closed, the residual risk is judged **medium** — driven by R2 (home inference) and R5
(sole controller, no security team), both mitigated but neither eliminated. **This is below the
threshold that would require prior consultation with the supervisory authority under Art. 36.**

> **As of 2026-07-12, §7.5 is not closed** — the Gemini training question is settled (paid tier), but
> **no processor DPA is signed or filed**. So the "medium" judgement above is a statement about where
> we will be, not where we are. Saying otherwise would be precisely the laundering §0 warns against.

That judgement is only valid **while the pilot is small and consists of informed adults who know the
controller personally.** It does not survive growth. Before onboarding users who are not personally
known to the controller, this DPIA must be re-run — and at that point the Art. 37 DPO question and
the "large scale" thresholds move too.

---

## 8. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-14 | 0.1 | First draft, from PRD §16 and the implemented controls | Claude (Opus 4.8), for the controller |
| 2026-07-14 | **0.2** | **Rev 2 — Trip Mode and push (E29–E31).** New purpose P5; background-location, device-token and notification-decision categories; FCM as a recipient; risks R8–R10 and the §3.4 narrative; consent basis for Trip Mode (§4.2) with the wording in CONSENT.md §2A (C3); the five controls in §5.9. Closes the §4.2 "consent text not written" item and ROPA B12. | Claude (Opus 4.8), for the controller |

**Next review:** before the first user outside the pilot group, or on any change to the categories of
data, the processors, or the retention numbers in `config/privacy.php` — whichever comes first.

**This draft has not been reviewed by a lawyer.** It was written by an LLM with full access to the
codebase, which makes §2 and §5 unusually well-grounded — every control cited is running code with a
test — and makes §3, §4 and §6 exactly the kind of legal judgement an LLM should not be the last word
on. The open items in §7 are the ones to hand to a reviewer first, and §3.2 is the one that matters.
