# DPIA — Data Protection Impact Assessment

**Travel Companion AI** · GDPR Article 35
**Status: DRAFT — not yet reviewed. Must be reviewed before any user outside the pilot group.**
**Version:** 0.1 (2026-07-14) · Policy version: `config/privacy.php` → `v1`

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

### 2.4 Recipients and processors

| Recipient | What it receives | Basis for the transfer |
|---|---|---|
| **Hetzner / hosting (Germany)** | everything (it is the database) | EU/EEA — no transfer |
| **Google Places** (`places.googleapis.com`) | a place's name + coordinates, to verify opening hours | US — see §6 |
| **Google Routes** (`routes.googleapis.com`) | **the user's origin coordinates** + a destination | US — see §6 |
| **Google Gemini** (LLM) | place evidence + `part_of_day`, `travel_mode`, `walk_minutes`, `city_name` | US — see §6 |
| **Open-Meteo** | the coordinates of an H3 tile | EU (Germany) |
| **Overpass / OpenStreetMap** | region bounding boxes only — **no user data at all** | n/a |

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

R7 deserves a word. Consent must be **freely given** (Art. 4(11)), and a pilot group of three people
who know the founder personally is close to the definition of social pressure. Mitigation: the pilot
must be able to leave, delete everything, and refuse research consent **without it being awkward** —
which is a design and a social problem, not just a legal one.

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

**OPEN:** the consent *text* itself has not been written. It must name: what is collected, that a
profile is inferred, that the profile can reflect sensitive interests (§3.2), who receives data
(§2.4), and how to withdraw.

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

**OPEN — for the legal reviewer:**
- Confirm the *specific* Google entity and terms we contract with, and that they cover the DPF or
  SCCs for **Maps Platform** and **Gemini API** specifically (they are different products with
  different terms, and it is a mistake to assume one covers the other).
- Confirm whether Gemini API input is used for training under the terms we accept. **If it is, that
  is a blocker** — even though we send no identity, place evidence + city + time is still processing
  we would be handing over for the vendor's purposes rather than ours.
- The DPF is under active legal challenge (*Latombe*). If it falls, we need SCCs + a transfer impact
  assessment. Plan for that rather than be surprised by it.

---

## 7. Residual risk and what is NOT yet done

Being honest here is the whole point of the exercise.

### 7.1 OPEN — Art. 9 consent (§3.2) · **BLOCKER**
The taste profile can infer religious belief. Explicit consent must be obtained, and the consent
text must say so. **Do not onboard a user outside the pilot until this is done.**

### 7.2 OPEN — consent text and a privacy notice (Arts. 13–14)
Neither exists. A DPIA is not a privacy notice, and this document is not user-facing.

### 7.3 OPEN — Records of processing (Art. 30)
The <250-person exemption does **not** apply: our processing is not occasional, and (per §3.2) may
involve Art. 9 data. A ROPA is required. Most of it can be lifted from §2 of this document.

### 7.4 OPEN — breach procedure (Arts. 33–34)
72 hours is not long, and there is currently no written procedure and no rehearsed path to the
supervisory authority. With a sole controller this is the control most likely to fail under stress,
because it depends entirely on one person noticing.

### 7.5 OPEN — processor agreements (Art. 28)
Confirm a DPA is in place with the hosting provider and with Google.

### 7.6 Accepted residual risk
With §7.1–§7.5 closed, the residual risk is judged **medium** — driven by R2 (home inference) and R5
(sole controller, no security team), both mitigated but neither eliminated. **This is below the
threshold that would require prior consultation with the supervisory authority under Art. 36.**

That judgement is only valid **while the pilot is small and consists of informed adults who know the
controller personally.** It does not survive growth. Before onboarding users who are not personally
known to the controller, this DPIA must be re-run — and at that point the Art. 37 DPO question and
the "large scale" thresholds move too.

---

## 8. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-14 | 0.1 | First draft, from PRD §16 and the implemented controls | Claude (Opus 4.8), for the controller |

**Next review:** before the first user outside the pilot group, or on any change to the categories of
data, the processors, or the retention numbers in `config/privacy.php` — whichever comes first.

**This draft has not been reviewed by a lawyer.** It was written by an LLM with full access to the
codebase, which makes §2 and §5 unusually well-grounded — every control cited is running code with a
test — and makes §3, §4 and §6 exactly the kind of legal judgement an LLM should not be the last word
on. The open items in §7 are the ones to hand to a reviewer first, and §3.2 is the one that matters.
