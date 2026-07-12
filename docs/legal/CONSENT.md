# Consent register — the exact words, and whether they hold

**Travel Companion AI** · GDPR Arts. 4(11), 7, 9(2)(a)
**Status: DRAFT — not reviewed by a lawyer.** Closes DPIA §7.1 (review) and §7.2 (text).
**Version:** 0.1 (2026-07-12) · Consent version: `config/privacy.php` → `profiling_consent_version = v1`

---

## 0. What this document is for

Art. 7(1) says the controller must **be able to demonstrate** that consent was given. That
is an evidentiary obligation, and it has a consequence people miss: *you must be able to
produce the words the user actually saw*. A timestamp proves someone clicked. It does not
prove what they agreed to.

So this file is the archive. For each consent we ask for: the exact on-screen text, the
version it belongs to, where the record is stored, and how it is withdrawn. When the text
changes, the version bumps, the old text stays here, and every existing consent is
invalidated — because a consent that silently stretches to cover whatever we build next is
not a consent.

---

## 1. The three consents

| ID | What is consented to | Article | Version | Stored in | Default |
|---|---|---|---|---|---|
| **C1** | Being profiled — the taste model, and the calibration answers it learns from | **9(2)(a)** explicit + 6(1)(a) | `v1` | `users.profiling_consent_at`, `users.profiling_consent_version` | **Off** |
| **C2** | Research use — traces keep full-precision coordinates past 30 days | 6(1)(a) | (unversioned — see §4.3) | `users.research_consent` | **Off** |
| **C3** | — | — | — | — | — |

There is deliberately **no C3 for location**. Location is processed under Art. 6(1)(b),
not consent — see ROPA §3. Asking for "consent" to the one thing the product cannot work
without would produce a consent that is not freely given (Art. 4(11)), which is worse than
not asking. This is a point where the intuitive answer ("ask for everything") is the wrong
one.

---

## 2. C1 — explicit consent to be profiled

### 2.1 The exact text, v1

Shown on `/welcome` (`resources/js/pages/calibrate/welcome.tsx`), **before** the nine
calibration pairs, above the only button on the screen. The checkbox starts **unticked**.

> **Before we start.**
>
> Nine quick pairs, about a minute. There's no right answer — I'm learning which way you
> lean, so I can stay quiet about the rest.
>
> ☐ **I'm happy for you to build a picture of my taste from these answers and from what I
> do next.**
> *It's a guess about what you'd enjoy — but because it learns from the kinds of places you
> pick, it can end up reflecting personal things: an interest in religious sites, for
> example, or somewhere you go for your health. You can see exactly what I've concluded,
> and delete it, at any time.*
>
> What I collect, who sees it, and how long I keep it: [the privacy notice](/privacy-policy).
>
> [ Show me the first pair ]  ← disabled until the box is ticked
>
> *No thanks — don't learn my taste*
>
> You'll still get suggestions. They'll just be less about you.

The same consent is mirrored in Settings → Privacy, where it is the withdrawal control:

> **Turning this off stops the learning AND deletes what I concluded: I shouldn't keep a
> guess I no longer have your permission to have made. You'll still get suggestions, just
> less about you.**

### 2.2 Does it meet the Art. 9(2)(a) bar? — the review

This is the question the dev LLM correctly identified as the real one. Taking each element
in turn.

| Requirement | Source | Verdict |
|---|---|---|
| **Freely given** | 4(11) | **Pass.** Refusal is one tap, sits on the same screen, and is not styled as a failure. The product demonstrably still works: α = 0, cold-start ranking (SCORING §6). Refusal costs personalisation, not the service. |
| **Specific** | 4(11) | **Pass.** It is about one thing — the taste profile — and is not bundled with the account, the location, or the terms. |
| **Informed** | 4(11), Recital 42 | **Pass, and unusually good.** It names the uncomfortable consequence in the user's own language ("can end up reflecting personal things, like an interest in religious sites") rather than burying it. |
| **Unambiguous, affirmative act** | 4(11), Recital 32 | **Pass.** Unticked box, and the primary button is `disabled` until it is ticked. Not pre-ticked, not inferred from silence, not a side effect of pressing the only button. |
| **Explicit** (the higher Art. 9 bar) | 9(2)(a) | **Pass.** A dedicated statement, ticked separately from the action that follows. This is what "explicit" means in EDPB Guidelines 05/2020 §4: an express statement, not merely an unambiguous action. |
| **Withdrawable as easily as given** | 7(3) | **Pass, and then some.** One click in Settings, no password, no retention dark-pattern — and withdrawal *deletes the profile*. |
| **Demonstrable** | 7(1) | **Pass, with a gap.** Timestamp + version are stored. The version is only meaningful because the text is archived — in this file. Before v1 there was nowhere to look it up. |
| **Informed as to recipients** | 13(1)(e) | **Gap — see §2.3.** |

**Overall: I think this consent holds.** It is better than most Art. 9 consents I would
expect to see, and the two design decisions behind it — gating the *learner* rather than
the callers, and deleting the profile on withdrawal — are the ones that make it survive
scrutiny rather than merely look compliant.

Two things I would change, neither fatal:

### 2.3 Change 1 — the consent does not link to the privacy notice

Informed consent means informed about **who receives the data and where it goes** (Art.
13). The screen says what is inferred and that it can be deleted. It says nothing about
the fact that any of this leaves the machine.

That is defensible *for this consent specifically* — the taste profile is never sent to
Google or Gemini, and that is verified in code, so the profiling consent is arguably not
the place to disclose transfers. But the user has no route from this screen to the notice
that would tell them. **Add a link.** One line, under the checkbox:

> *What I collect, who sees it, and how long I keep it: [Privacy](/privacy).*

This is cheap, and it converts "informed about the profiling" into "informed", which is
the standard the article actually sets.

### 2.4 Change 2 — "personal things, like an interest in religious sites" undersells the mechanism

The text names religion, because religion is the example the taxonomy makes concrete
(`religious_sacred`, `spiritual`). But the mechanism is generic: DPIA §3.2 says so, and the
ROPA records health as reachable by the same route (a repeated pattern around a clinic).

If the consent names only religion and the profile later infers something else, **the
consent does not cover the something else** — and the versioning machinery, which exists
precisely to catch this, will not fire, because nobody will think to bump it.

The honest fix is one word — *like* is already doing this work, but weakly. Prefer:

> *It's a guess about what you'd enjoy — but because it learns from the kinds of places
> you pick, it can end up reflecting personal things: an interest in religious sites, for
> example, or somewhere you go for your health. You can see exactly what I've concluded,
> and delete it, at any time.*

That is still plain language, still one sentence, and it now covers the mechanism rather
than one instance of it. **If you adopt this, it is a text change within v1** (it widens
the disclosure without widening the processing, so existing consents remain valid — the
user was told *less* than the new text says, but nothing we do exceeds what they agreed
to). If you ever widen the *processing*, that is a `v2` and every consent must be re-asked.

### 2.5 What the consent gates, in code

Worth recording, because it is the part that makes the consent real rather than decorative:

- `FacetWeightLearner` — the single place a facet weight can move — refuses without consent.
  Gating the learner rather than its callers means a caller added next year cannot quietly
  re-open a hole.
- The calibration POST refuses to write `profile_signals` without consent. This was a hole
  found by a test: the *screen* was gated but the *answers* were still landing. Those rows
  are not metadata about the profiling — they **are** the sensitive data.
- Withdrawal (`SetProfilingConsent::withdraw`) deletes the taste profile in the same
  transaction that clears the flag. Holding a vector from which religious belief can be
  deduced is itself processing; "stop learning but keep what you inferred" would leave us
  storing Art. 9 data with **no lawful basis at all** — a worse position than never asking.

---

## 3. C2 — research consent

### 3.1 The exact text

Settings → Privacy (`resources/js/pages/settings/privacy.tsx`). Off by default.

> **Research consent**
>
> Off by default. With it on, your recommendation traces keep their exact coordinates so
> they can be used to test whether changes to the ranking make it better or worse. With it
> off, those coordinates are deleted after 30 days like everything else.
>
> [ Off — turn it on ]

### 3.2 Review

**Holds.** Freely given (refusing degrades nothing — DPIA §4.2 requires this and it is
true), specific, informed, affirmative, off by default, one-click reversible. It buys a
precisely stated thing — an exemption from the 30-day coarsening — and says so in numbers.

### 3.3 One gap: it is not versioned

`research_consent` is a bare boolean. C1 is versioned; C2 is not. If the research purpose
ever widens — say, from "test whether ranking changes are better" to "train a model" — the
existing `true` values would silently carry over to a purpose nobody agreed to.

**Recommend:** mirror C1. Add `research_consent_at` + `research_consent_version` and treat
a version mismatch as "not consented". This is a small migration and it removes an entire
class of future mistake. Not a blocker for the pilot; do it before the purpose changes,
because *after* is too late by construction.

---

## 4. Rules for changing any of this

1. **Widening the processing bumps the version.** Not the wording — the *processing*. If
   the profile starts inferring something it did not infer before, that is a new version,
   and `UserProfilingConsent::granted()` will correctly report `false` for everyone until
   they are asked again. That is the intended behaviour, not a bug to be worked around.
2. **Narrowing or clarifying the wording does not.** Telling users more than they were told
   before does not invalidate what they agreed to.
3. **Every version's text is archived in this file, verbatim.** Art. 7(1) is an evidentiary
   burden and screenshots are not a system of record.
4. **A consent that must be revoked is not a consent.** Every default in this file is off,
   and the migrations say so out loud. Keep it that way.

### 4.1 Version history

| Version | Date | Consent | Change |
|---|---|---|---|
| `v1` | 2026-07-12 | C1 | First explicit consent. Text as in §2.1. |

---

## 5. Open items

| # | Item | Severity | Status |
|---|---|---|---|
| **C-1** | Link the consent screen to the privacy notice (§2.3) — it is what makes the consent *informed* (Art. 13(1)(e)) | High | **DONE** — links to `/privacy-policy`; the sign-up form does too, because Art. 13 wants the notice available *when the data is obtained* |
| **C-2** | Widen the C1 text from "religious sites" to the mechanism (§2.4) | Medium | **DONE** — now names health as well. A text change within `v1`: it widens the *disclosure*, not the *processing*, so existing consents remain valid |
| **C-3** | Version the research consent (§3.3) | Low now, structural later | OPEN |

**A note on C-2 and why the version did not bump.** Widening what a user is *told* does not
invalidate what they agreed to — they were told less than the new text says, and nothing we
do exceeds what they agreed to. Widening what the profile *infers* is the opposite case, and
that is a `v2` and a re-ask. `UserProfilingConsent::granted()` will correctly report `false`
for everyone the moment `profiling_consent_version` moves, which is the intended behaviour and
not a bug to be worked around.

---

## 6. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-12 | 0.1 | First register. Reviewed C1 against Art. 9(2)(a) and C2 against Art. 7; archived both texts. | Claude (Opus 4.8), for the controller |

**Not a sign-off.** This is a considered opinion from a model with full sight of the code
and the screens, which makes §2.5 and §3 reliable as statements of *what the system does*.
Whether the words in §2.1 clear the Art. 9(2)(a) bar is a legal judgement, and my view that
they do is worth exactly what a well-reasoned second opinion is worth — which is not the
same as protection.
