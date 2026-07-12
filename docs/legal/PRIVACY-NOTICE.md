# Privacy

**This is the user-facing notice** (GDPR Arts. 13–14). It is written to be read by a
person, not by a regulator — but it has to satisfy both, so every Art. 13 element is in
here somewhere. The internal record behind it is [`ROPA.md`](ROPA.md); the assessment is
[`DPIA.md`](../DPIA.md).

**Status: LIVE at `/privacy-policy`** (and `/terms-of-service`), served by `LegalController`.
Both routes sit outside the `auth` group on purpose: Art. 13 wants the notice available "at the
time when personal data are obtained", so the sign-up form and the consent screen both link to
it. A notice you can only read once you already have an account is a receipt, not a notice.

The retention figure is read from `config/privacy.php` rather than typed into the page — a
notice that says "30 days" while the job enforces 60 is a false statement to a data subject.

**Publication gate — cleared.** v0.1 of this file said it must not ship until ROPA **B1** and
**B3** were fixed, because "we destroy precise location" and "deleting your account deletes
everything" were not true while Pulse recorded coordinates from the Open-Meteo URL. **B1 is
fixed** (query strings stripped from recorded URLs; `tests/Feature/Privacy/TelemetryLeakTest.php`).
**B3** — Open-Meteo should receive a tile centroid, not a person — is in progress, and until it
lands the "Who else sees your data" section below tells the truth about it rather than the
intention. That is the right way round.

**Last updated:** 2026-07-12 · **Version:** 0.2

---

## The short version

- I collect **where you are**, but only while you have an explore session open, and only
  when the app is in front of you. Never in the background.
- I keep those **exact coordinates for 30 days**, then destroy them and keep only a
  ~0.7 km² cell.
- You can declare a **home zone**. Inside it I store nothing precise, learn nothing, and
  suggest nothing — the coordinate is never written down at all.
- I build a **picture of your taste**, but only if you say yes, and I delete it the moment
  you say no.
- Your position goes to **Google** to compute walking times, and to **Open-Meteo** for the
  weather. It goes without your name, your account, or any identifier attached.
- You can **export everything** or **delete everything**, from Settings, whenever you like.

The rest of this page is the detail behind those six lines.

---

## 1. Who is responsible for your data

**Mats Bergsten** — currently a person, not a company. That means there is no support desk;
there is an inbox, and I read it.

**Contact:** rockstoneaidev@gmail.com

There is no Data Protection Officer, and one is not required at this size. If that changes,
this page changes.

---

## 2. What I collect, why, and what allows me to

| What | Why I need it | What allows me to (GDPR) |
|---|---|---|
| **Your name and email** | To have an account you can log back into | Performance of our agreement |
| **Your Google account details**, if you sign in with Google (your Google ID, email, name, profile picture) | So "Sign in with Google" works | Performance of our agreement |
| **Your precise location**, while an explore session is open | To suggest things that are actually near you. This is not optional — a recommendation engine that doesn't know where you are cannot recommend anything. | Performance of our agreement |
| **Your time budget, travel mode, and situation** (how long you have, whether you're walking, whether it's raining, your phone's battery level) | To suggest things you can actually reach and do before the light goes | Performance of our agreement |
| **What I suggested and why** — every score and input behind a recommendation | So the app can answer "why did I get this?", which is the difference between a companion and a slot machine | Performance of our agreement |
| **What you did about it** — accepted, kept, dismissed, visited | To stop suggesting things you keep ignoring | Performance of our agreement (and consent, where it feeds the taste profile) |
| **Your taste profile** — what I've inferred you like | To personalise. **Only with your consent.** | **Your consent**, which you can withdraw at any time |
| **Technical and security data** — logins, errors, performance | To keep the thing running and not get broken into | My legitimate interest in a service that works |

**You are never obliged to give me any of this.** But the honest consequence: without
location there is no product, and without the taste profile there is a product that doesn't
know you. Both are supported outcomes. Neither is punished.

---

## 3. The uncomfortable part, stated plainly

I want to say this in the notice rather than bury it, because it is the single most
sensitive thing this app does.

**The taste profile can end up revealing things about you that you never told me.**

It learns from the *kinds of places you choose*. If you keep choosing churches, chapels or
cathedrals, the profile accumulates a high weight for religious and spiritual places — and
that weight is, in substance, **a statement about your religious beliefs**, inferred from
your behaviour. The same mechanism could reach your health, if you repeatedly went
somewhere for it.

European law treats data about religious belief and health as **special category data**
(Art. 9), and it may not be processed without your **explicit consent** — which is why the
box on the welcome screen is unticked, why the button is greyed out until you tick it, and
why the wording says what it says.

**If you turn it off, I delete the profile.** Not "stop updating it" — delete it. Keeping a
guess about your beliefs after you have withdrawn permission to make it would be exactly
the thing the law forbids, and exactly the thing you'd be right to be angry about.

---

## 4. Who else sees your data

I use a small number of outside services. **None of them are ever told who you are.** No
name, no email, no account ID, no session ID, no cookie goes with any of these requests.

| Service | What it gets | Where | Why it's allowed |
|---|---|---|---|
| **Hetzner** — hosting | Everything. It is the database. | 🇩🇪 Germany | It's in the EU |
| **Google Routes** | **Your exact coordinates**, plus a place you might walk to | 🇺🇸 USA | EU–US Data Privacy Framework, backed by standard contractual clauses |
| **Google Places** | A *place's* name and coordinates — to check whether it's actually open. Nothing about you. | 🇺🇸 USA | Same as above |
| **Google Gemini** (the AI that writes the descriptions) | Facts about a place, plus the time of day, how you're travelling, how far it is, and which city you're in. **No identity, no profile, no coordinates.** | 🇺🇸 USA | Same as above |
| **Open-Meteo** — weather | **Your coordinates** | 🇩🇪 Germany | It's in the EU |
| **Resend** — email | Your **email address**, when I send you a password reset or a verification link | 🇺🇸 USA | EU–US Data Privacy Framework |
| **Google** — if you sign in with Google | The sign-in itself | 🇺🇸 USA | You chose to use it |

**The sharpest one, said out loud:** to tell you it's a *seven-minute walk* rather than
guessing, I have to send where you're standing to Google. That's a real transfer of a real
coordinate to a US company. It goes anonymously — Google receives a point on a map, not a
person — but it goes, and you should know that it does.

**What never leaves:** your name, your email, your taste profile, your feedback history,
and your home zone. These stay on the server in Germany.

---

## 5. How long I keep things

| What | How long | Then |
|---|---|---|
| **Your exact coordinates** | **30 days** | Destroyed. Permanently — not archived, not "soft deleted". What survives is a ~0.7 km² grid cell: enough to learn that you like waterfront viewpoints, not enough to find your door. |
| **Anything inside your home zone** | **Never stored precisely at all** | There is nothing to delete, because it was never written down |
| **What I suggested and why** | Indefinitely — it's how the app explains itself | Its coordinates are stripped on the same 30-day clock |
| **Your taste profile** | Until you reset it, withdraw consent, or delete your account | Deleted |
| **Your feedback history** | Until you delete your account | Deleted |
| **Your account** | Until you delete it | Deleted |
| **Opening hours from Google** | 10 minutes, in memory | Evicted. **Never written to the database.** |

If you turn on **research consent** (off by default, in Settings), your recommendation
traces keep their exact coordinates past 30 days, so they can be used to test whether
changes to the ranking make it better or worse. That's the whole of what it buys, and
turning it off puts you back on the 30-day clock.

---

## 6. What you can do

All of these are in **Settings → Privacy**, and all of them work today.

- **See everything I hold** — one button, and you get a JSON file with all of it: your
  trips, everything I showed you and why, everything you told me back, and the taste
  profile itself. Not a summary. The actual thing that decides what you see. *(Art. 15, 20)*
- **Delete your account** — and everything attached to it, permanently. The feedback
  history goes too. It's valuable to me and it's yours, and "delete my account" is not a
  negotiation. *(Art. 17)*
- **Delete a trip's location history** — without deleting the trip.
- **Reset the taste profile** — forget what you *concluded* about me, keep what I did.
- **Withdraw profiling consent** — which also deletes the profile. One click. No password,
  no "are you sure you want to lose your personalised experience". *(Art. 7(3))*
- **Declare a home zone** — a point and a radius. Inside it, I don't learn, don't suggest,
  and don't store a coordinate. Ever.
- **Correct anything that's wrong** *(Art. 16)*, **object to processing** based on my
  legitimate interests *(Art. 21)*, or **ask me to restrict** what I do with your data
  *(Art. 18)* — email me.

**And you can complain about me.** If you think I've handled your data badly, you can
complain to the Swedish supervisory authority, **IMY (Integritetsskyddsmyndigheten)** —
imy.se — or to the authority where you live. You do not have to ask me first, and I'd
rather you had the option than not.

---

## 7. Decisions made about you by machine

The app scores places against a model of your taste and shows you the top few. That is
automated, and it is the entire point.

It is **not** the kind of automated decision-making the law restricts (Art. 22), because
nothing here has a legal or similarly significant effect on you: it suggests a viewpoint,
you ignore it, nothing happens. Most of the time the correct output is silence.

You can still ask it **why**. Every card can tell you what it weighed and how, because
every recommendation stores its full reasoning. That's not there because the law demands
it. It's there because a companion that can't say why it suggested something doesn't
deserve to be trusted.

---

## 8. Children

This app is not for children, and I don't knowingly collect their data. Registration is
currently invite-only, so I know who every user is.

---

## 9. Changes to this notice

If I change what I collect, who gets it, or how long I keep it, I'll update this page and
say so. If the change means the taste profile starts inferring something new, **your
existing consent stops counting and I'll ask you again** — that's built into how consent is
stored, not a promise I'm making.

---

## Appendix — Art. 13 compliance checklist (internal; strip before publishing)

| Art. 13 requirement | Where |
|---|---|
| 13(1)(a) Identity + contact of controller | §1 |
| 13(1)(b) DPO contact | §1 — none, not required |
| 13(1)(c) Purposes + legal basis | §2 |
| 13(1)(d) Legitimate interests, where relied on | §2 (security row) |
| 13(1)(e) Recipients | §4 |
| 13(1)(f) Third-country transfers + safeguards | §4 |
| 13(2)(a) Retention periods | §5 |
| 13(2)(b) Rights: access, rectification, erasure, restriction, objection, portability | §6 |
| 13(2)(c) Right to withdraw consent | §6, §3 |
| 13(2)(d) Right to complain to a supervisory authority | §6 |
| 13(2)(e) Whether provision is obligatory + consequences | §2, closing paragraph |
| 13(2)(f) Automated decision-making + meaningful information about the logic | §7 |
| Art. 9 special category data | §3 |
| Recital 39 — intelligible, plain language | Throughout. That's the point. |

**Blockers before publication** (see ROPA §9):
- **B1** — §5's "destroyed permanently" and §6's "delete everything" are false while Pulse
  retains user ids and coordinates outside the retention job and outside erasure.
- **B3** — §4 says Open-Meteo gets your coordinates, which is true *today* but should not
  be: it should get a tile centroid. Fix the code, then this row improves.
- **B4** — §2 relies on "performance of our agreement" six times. **There is no agreement.**
  A Terms of Service must exist, or this basis collapses.
