# Breach procedure

**Travel Companion AI** · GDPR Arts. 33–34
**Status: DRAFT — not reviewed by a lawyer.** Closes DPIA §7.4.
**Version:** 0.1 (2026-07-12)

---

## 0. Read this before you need it

The dev LLM was right that this is barely a legal document. It is an operational one, and
it only works if it has been read **before** the day it is needed. A procedure discovered
during an incident is not a procedure; it is a document.

It is also the control most likely to fail here, and the reason is structural: **the
controller is one person.** Every other control in this system is code that runs whether
anyone is paying attention or not. This one depends entirely on a human noticing something,
at an unknown hour, possibly while abroad, possibly while the thing that broke is the
laptop they would have used to respond.

So the design goal is not completeness. It is **"can one tired person execute this from a
phone, in France, at 2am, without having to think."**

**The 72 hours is not 72 hours.** It starts when you become *aware*, not when you finish
investigating, and it runs through weekends. Realistically you have one evening.

---

## 1. What counts as a breach

Art. 4(12): a breach of security leading to **accidental or unlawful destruction, loss,
alteration, unauthorised disclosure of, or access to** personal data.

Note the three limbs. It is not only "someone stole the data":

- **Confidentiality** — someone saw data they shouldn't. *(the one everyone thinks of)*
- **Integrity** — data was altered without authorisation.
- **Availability** — **data was destroyed or lost, including by you.** A botched migration
  that drops a table, or a backup that turns out not to restore, is a personal data breach.
  This one catches people out.

**A breach does not require an attacker.** Sending an export to the wrong email address is
a breach. So is a bug that shows user A user B's trip.

---

## 2. The first hour

Do these in order. Do not skip to investigating.

### Step 1 — Write down the time you found out. (30 seconds)

This timestamp is the start of the 72-hour clock and you will be asked for it. Put it in
the incident log (§6) before you do anything else, because you will not remember it
accurately afterwards.

### Step 2 — Stop the bleeding. (minutes)

Containment beats diagnosis. In rough order of what is likely here:

| If | Do this first |
|---|---|
| A credential or API key leaked | **Rotate it.** `GOOGLE_MAPS_API_KEY`, `GEMINI_API_KEY`, `RESEND_API_KEY`, `APP_KEY`, DB password, `GOOGLE_CLIENT_SECRET`. Rotate first, understand later. |
| The app is leaking data through a bug | **Take it down.** Three pilot users can survive an outage. They cannot un-see each other's data. |
| The server is compromised | Isolate it. Do **not** wipe it — you will need it to work out what was taken, and "we don't know what was accessed" forces you to assume the worst. |
| An admin account is compromised | Revoke sessions and tokens, reset the password, check `activity_log` for what it did. |
| Data was destroyed (migration, bad delete) | **Stop writing.** Confirm the backup restores *before* you touch anything else. |

### Step 3 — Preserve the evidence. (minutes)

Before restarting anything: copy the logs off the box. `storage/logs/`, the Traefik access
log, Postgres logs, and the relevant `pulse_entries` window. If you restart into a clean
state you will destroy the only record of what happened, and you will then have to notify
on the assumption that everything was taken.

### Step 4 — Answer four questions. (the rest of the hour)

Write the answers down, even if the answer is "unknown". Art. 33(3) requires all four, and
"unknown" is an acceptable answer to a regulator in a first notification. Guessing is not.

1. **What data?** Which categories, which tables? (→ ROPA §4)
2. **Whose, and how many?** How many data subjects — a number, or an upper bound.
3. **What are the likely consequences** for those people?
4. **What have you done, and what will you do?**

---

## 3. Do I have to notify? — the decision

Two separate decisions. Do not conflate them.

```
                    ┌─────────────────────────────────────┐
                    │  Is there a RISK to people's        │
                    │  rights and freedoms?               │
                    └─────────────────────────────────────┘
                        │                       │
                    NO  │                       │ YES / UNSURE
                        ▼                       ▼
             ┌────────────────────┐   ┌────────────────────────────┐
             │ Do NOT notify IMY. │   │ NOTIFY IMY within 72 hours │
             │ But you MUST still │   │ of becoming aware.         │
             │ log it internally  │   │ (Art. 33)                  │
             │ (Art. 33(5)) with  │   └────────────────────────────┘
             │ the reasoning.     │                │
             └────────────────────┘                ▼
                                      ┌────────────────────────────┐
                                      │ Is the risk HIGH?          │
                                      └────────────────────────────┘
                                          │                │
                                      NO  │                │ YES
                                          ▼                ▼
                                   ┌────────────┐  ┌──────────────────────┐
                                   │ IMY only.  │  │ ALSO tell the USERS, │
                                   └────────────┘  │ without undue delay. │
                                                   │ (Art. 34)            │
                                                   └──────────────────────┘
```

**When unsure, notify.** Late notification is a finding. Unnecessary notification is not an
offence, and a controller who over-notified has never been fined for it. The asymmetry is
enormous and it should drive the decision.

**The Art. 34 exception worth knowing:** you do not have to tell users if the data was
**encrypted** and the key was not taken (Art. 34(3)(a)). This is the single strongest
argument for encryption at rest — it is the difference between an email to your users and a
line in a log. It is also currently **unverified** for this system (ROPA §9, B8).

### 3.1 What "high risk" means for *this* system, concretely

Location data is not ordinary data. Judge accordingly:

| Scenario | Risk | Notify IMY? | Notify users? |
|---|---|---|---|
| **`context_events` or `explore_sessions` disclosed** — raw coordinate traces | **High.** Four spatio-temporal points identify 95% of people (de Montjoye 2013). This reveals where someone *was*, and by inference where they live. | **Yes** | **Yes** |
| **`users.home_zone_center` disclosed** | **High.** This is literally people's home addresses. | **Yes** | **Yes** |
| **`user_taste_profiles` or `profile_signals` disclosed** | **High — this is Art. 9 data** (DPIA §3.2). A leaked religion inference is not a leaked preference. | **Yes** | **Yes** |
| **`recommendation_feedback` disclosed** | **High.** "Visited" events on religious sites are the same inference by another route. | **Yes** | **Yes** |
| Full database dump | **High**, obviously. Assume all of the above. | **Yes** | **Yes** |
| `users` table only — name, email, password hashes | Medium. Hashes are bcrypt; the emails are a real disclosure. | **Yes** | Probably — force a password reset regardless |
| Google/Gemini API key leaked, no user data touched | **No risk to data subjects** — it is a *financial* risk to the controller. | **No** (log it) | No |
| `places_core` / world model leaked | None. It's OpenStreetMap. It's already public. | No | No |
| Availability loss with a good backup, restored quickly | Low. Log it. | Probably not — record why | No |

**The pattern:** in this system, almost anything that touches a table with a `user_id`
is high risk, because the payload is *location and inferred belief*. Do not reason from
"only three users, so it's small". Severity is about the harm to those people, not the
count — and three people's complete movement history is a serious disclosure.

---

## 4. Notifying IMY (Art. 33)

**Who:** Integritetsskyddsmyndigheten (IMY), Sweden — because the controller is established
in Sweden. **The servers being in Germany does not change this.** Jurisdiction follows the
controller's establishment (Art. 56), not the hardware. Hetzner would have its *own*
obligations as a processor toward *us* (Art. 33(2)), which is a different thing.

**How:** IMY takes breach reports through an e-service on **imy.se**. Find the form
**now**, while nothing is on fire, and put the link in §7. Do not discover the submission
route during the incident.

**Deadline:** 72 hours from awareness. If you miss it, **notify anyway** and explain the
delay — Art. 33(1) explicitly allows a reasoned late notification. A late notification is
far better than a missing one.

**A partial notification is allowed and is usually the right call** (Art. 33(4)): file
within 72 hours with what you know, and supplement as you learn more. Do not sit on it for
three days trying to produce a complete picture.

### 4.1 Template

> **Nature of the breach:** [what happened, in two sentences]
> **When it occurred:** [or "unknown; first evidence at …"]
> **When we became aware:** [the timestamp from Step 1]
> **Categories of personal data:** [from ROPA §4 — be specific: "precise location traces
> (`context_events.location`), inferred preference profiles (Art. 9 by inference — see our
> DPIA §3.2)"]
> **Categories and approximate number of data subjects:** [n users, all pilot participants]
> **Approximate number of records:** [n]
> **Likely consequences:** [e.g. "re-identification of data subjects from location traces;
> inference of home address; inference of religious belief from the taste profile"]
> **Measures taken:** [containment, rotation, restoration]
> **Measures proposed:** [what's next]
> **DPO / contact point:** Mats Bergsten, rockstoneaidev@gmail.com (no DPO — not required
> at this scale, see our DPIA §2.1)

Do not minimise in this document. A regulator reads a hundred of these and can tell.

---

## 5. Notifying users (Art. 34)

Required when the risk is **high** — which, per §3.1, is most breaches that touch user
tables here.

"Without undue delay" means **as soon as you have something true to say**. It does not
mean "after IMY replies".

**Say it in clear and plain language** (Art. 34(2)). Not a legal notice. These are three
people who know you.

### 5.1 Template

> **Subject: A security problem with Travel Companion, and what it means for you**
>
> On [date] I discovered that [plain description].
>
> **What was exposed:** [specifically — "the exact locations you visited between X and Y",
> not "certain data elements"].
>
> **What this means for you:** [honestly — "location history can reveal where you live";
> "the taste profile can reflect an interest in religious sites, and that was in the data"].
>
> **What I've done:** [...]
>
> **What you should do:** [change your password / nothing / …]
>
> You can delete your account and everything in it at any time, from Settings → Privacy.
> You can also complain to IMY (imy.se) — you don't need my permission and you shouldn't
> feel awkward about it.
>
> I'm sorry. Ask me anything: rockstoneaidev@gmail.com

That last paragraph is not decoration. The DPIA already identified (R7) that a pilot of
three friends is under social pressure by construction. A breach is exactly when that
pressure becomes a problem, and the notification is the place to defuse it explicitly.

---

## 6. The incident log — required even when you don't notify

**Art. 33(5) requires you to document every breach**, including the ones you decide not to
report, *including the reasoning for not reporting*. That record is what a regulator asks
for when they want to know whether your judgement is any good. A controller with an empty
log and one bad incident looks very different from one with a log full of considered
"no-risk, here's why" entries.

Keep it at `docs/legal/incidents/YYYY-MM-DD-slug.md`. One file per incident:

```markdown
# Incident — [slug]

- **Became aware:** 2026-08-03 21:14 CEST (how: [Horizon alert / user email / noticed by hand])
- **Occurred:** [or unknown]
- **Contained:** [timestamp + what]

## What happened

## Data affected
[Categories, from ROPA §4. Number of subjects. Number of records.]

## Risk assessment
[Risk / high risk / none. **And the reasoning.**]

## Notified?
- IMY: [yes, timestamp, ref] / [no — because …]
- Data subjects: [yes, timestamp] / [no — because …]

## What I changed so it can't happen again
```

The last section is the one with long-term value. It is also the first thing a regulator
looks for, because it distinguishes a controller who learns from one who apologises.

---

## 7. Fill these in now, not later

These are the things you will be hunting for at 2am if you don't.

| | |
|---|---|
| **IMY breach reporting URL** | ☐ **TO FILL** — find the e-service on imy.se and paste the direct link here |
| **Hetzner abuse/security contact** | ☐ **TO FILL** |
| **How Hetzner notifies *you* of a breach** (Art. 33(2) — they must, "without undue delay") | ☐ **TO FILL** — check the DPA; know which inbox it lands in |
| **Google Cloud security contact** | ☐ **TO FILL** |
| **Where the backups are, and when you last proved a restore works** | ☐ **TO FILL** — an unrestorable backup is an availability breach waiting to happen |
| **List of every secret that would need rotating** | ☐ **TO FILL** — write the actual list; you will not enumerate it correctly under stress |

---

## 8. How you would actually find out — and the honest answer

The uncomfortable part. Today, detection depends on:

- **Horizon** — you'd see failed jobs, if you were looking.
- **Pulse** — exceptions and slow queries, if you were looking.
- **The application log** — if you were looking.
- **A user telling you.**
- **Noticing.**

**Every one of these requires you to already be paying attention.** There is no alerting.
Nothing pages you. If the database were being dumped over a weekend, nothing in this system
would tell you, and the 72-hour clock would not start until you happened to look — which is
in your favour legally and catastrophic practically.

**The cheapest meaningful improvement, in order:**

1. **Alert on anything reaching the Horizon failed-job queue.** You already run Horizon;
   this is a webhook, not a project.
2. **Alert on unexpected outbound data volume** or a spike in `/settings/privacy/export`.
3. **Log admin actions** — `activity_log` already exists and already captures role changes.
   Widen it to position-emulation and any admin read of user data (ADMIN.md).
4. **Prove a restore.** Once. Write down that you did it and when.

None of these are required by Art. 33. All of them are what makes Art. 33 executable, and
the first one is an afternoon.

---

## 9. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-12 | 0.1 | First procedure. Risk table in §3.1 derived from the ROPA's data categories. | Claude (Opus 4.8), for the controller |

**Rehearse this once before the pilot.** Pick a scenario from §3.1 — "the `context_events`
table leaked" is the right one — set a timer for an hour, and walk it. You will find that
§7 is empty and that you cannot remember where the backups are, which is the entire point of
doing it on a Tuesday instead of during an incident.
