# Processor register and DPA status

**Travel Companion AI** · GDPR Art. 28, Ch. V
**Status: DRAFT — not reviewed by a lawyer.** Closes DPIA §7.5.
**Version:** 0.1 (2026-07-12)

---

## 0. What this is, and the one thing an LLM cannot do for you

Art. 28(3): a controller may only use a processor under **a contract**. Not a handshake,
not a signup — a written contract binding the processor to process only on your
instructions, to keep the data confidential and secure, to help you with data-subject
rights and breaches, and to delete or return the data at the end. If you are using a
processor without one, the processing is unlawful **regardless of how well you are treating
the data**. It is a paperwork failure with substantive consequences.

The dev LLM was right that most of this is an errand, and that no LLM can run it: someone
has to log in, accept a document, and file it. What an LLM *can* do — and what it did — is
tell you **the list is incomplete**. You cannot go and accept the right DPAs if you do not
know who your processors are.

**The vendor list below is derived from the code**, by enumerating every outbound `Http`
call in `app/`. It contains one processor that appears **nowhere** in the DPIA: **Resend**,
which receives every user's email address and is US-based.

---

## 1. The register

| # | Party | Role | Personal data it receives | Country | DPA status |
|---|---|---|---|---|---|
| **P1** | **Hetzner Online GmbH** — hosting | **Processor** | **All of it.** It is the database. | 🇩🇪 DE | ☐ **Not confirmed** |
| **P2** | **Google** — Maps Platform (Routes) | **Processor** | **Precise user coordinates** | 🇺🇸 US | ☐ **Not confirmed** |
| **P3** | **Google** — Maps Platform (Places) | **Processor** | None. Place names/coords only. | 🇺🇸 US | ☐ **Not confirmed** |
| **P4** | **Google** — Gemini API | **Processor** | Place evidence + part-of-day, travel mode, walk minutes, city. No identity, no coordinates. | 🇺🇸 US | ☐ **Not confirmed** — **and see §3.2** |
| **P5** | **Google** — OAuth / Sign-In | **Independent controller**, *not* a processor | The sign-in. Returns `sub`, email, name, avatar. | 🇺🇸 US | n/a — no DPA needed (see §4) |
| **P6** | **Resend** — transactional email | **Processor** | **User email addresses** + message bodies | 🇺🇸 US | ☐ **Not confirmed** · **MISSING FROM THE DPIA** |
| **P7** | **Open-Meteo** | Recipient — **status unclear**, see §5 | **Precise user coordinates** (today; should be a tile centroid) | 🇩🇪 DE | ☐ **None exists** |
| — | Overpass / OSM, Wikidata, Wikimedia Commons, DATAtourisme, Mérimée (data.culture.gouv.fr) | **Not processors** | **None.** Region bounding boxes at ingest time. No user is in the loop. | — | Not required |
| — | Overture Maps | Not a processor | Read from a local file. No network call. | — | Not required |

**Sub-processors.** Hetzner and Google both use sub-processors. Art. 28(2) requires your
authorisation for these; in practice both operate a general written authorisation with a
notification-of-change mechanism, which is standard and fine. Know that it exists.

---

## 2. The errands — do these in this order

Each is a login, a document, and a file. None require a lawyer. All of them are blocking.

### ☐ E1 — Hetzner: accept and file the AV-Vertrag

Hetzner publishes a **Auftragsverarbeitungsvertrag (AVV / DPA)** in the Robot/Cloud console
under contract or legal settings. Accept it. **Download the PDF and put it in
`docs/legal/dpa/`** — a DPA you cannot produce is a DPA you do not have.

While there, note two things you need for `BREACH-PROCEDURE.md` §7: **how Hetzner notifies
you of a breach** (Art. 33(2) obliges them to, without undue delay) and **which inbox that
lands in.**

### ☐ E2 — Google Cloud / Maps Platform: confirm the Cloud DPA applies

The **Google Cloud Data Processing Addendum** is normally incorporated by reference when
you accept the Cloud/Maps terms — but *incorporated by reference* is not the same as *you
have read which products it covers*.

**Confirm explicitly that it covers Maps Platform** (Routes and Places are Maps Platform
products, not Cloud products). This is the specific thing DPIA §6 flags and it is a real
trap: **Maps Platform and the Gemini API are different products with different terms**, and
assuming one DPA covers both is the mistake to avoid.

### ☐ E3 — Gemini API: confirm the terms, **and the training question** — §3.2

**This is the one that can change the architecture.** Do it before the others.

### ☐ E4 — Resend: accept the DPA

Resend publishes a DPA (resend.com/legal/dpa) — usually a click-to-accept in the dashboard.
Accept it, file it.

**Then add Resend to DPIA §2.4**, which currently does not mention it at all.

### ☐ E5 — Open-Meteo: fix the code, and the question disappears

See §5. This is a code fix, not an errand, and it is the cheapest item on the list.

### ☐ E6 — File everything in `docs/legal/dpa/`

Create the directory. One PDF per processor. Art. 5(2) accountability means being able to
**demonstrate** compliance, and "I definitely clicked accept" is not a demonstration.

---

## 3. Google, in detail

### 3.1 The transfer basis

Google LLC is certified under the **EU–US Data Privacy Framework**, which is an adequacy
decision (Art. 45). A transfer to a DPF-certified recipient is lawful without SCCs, and
Google's Cloud terms also incorporate **SCCs** as a contractual fallback.

**Verify the certification is live** rather than assuming it. The DPF maintains a public
list at dataprivacyframework.gov — check Google LLC is on it and that the certification
covers the relevant services.

**And plan for the DPF falling.** It is under active challenge (*Latombe*). If adequacy is
withdrawn, the SCC fallback carries the transfer, but you would then also owe a **Transfer
Impact Assessment**. Worth knowing now: if the DPF goes, **Routes and Gemini are the exposed
calls; Places is not**, because Places carries no user data.

### 3.2 The training question — **SETTLED 2026-07-12: paid tier**

We send Gemini **place evidence plus a four-field context object** (part of day, travel
mode, walk minutes, city name). No user id. No coordinates. No taste profile. That boundary
is real, it is deliberate, and it is verified in code (`EvidenceBundleBuilder`,
`ContextData`) — it means the LLM vendor cannot build a profile of our users even in
principle. It is the strongest thing in our position.

**But it never answered whether Google trains on what we send**, and that is a separate
question with a factual answer.

On the **free tier** of the Gemini API, Google generally **does** use prompts and responses
to improve its products. On the **paid tier**, it generally does not.

> **The controller has confirmed we are on the paid tier.** So Gemini is processing our data
> on our instructions and for our purposes only, which is what Art. 28 requires of a
> processor, and this is no longer a blocker.

Had it gone the other way, place evidence, city and time-of-day would have been processed
**for Google's purposes, not ours** — a processor acting outside the controller's
instructions (Art. 28(3)(a)), at which point Google would arguably not be our processor for
that processing at all, but a controller in its own right, for a purpose we had disclosed to
nobody.

**Worth naming the shape of what we now depend on.** The lawfulness of the entire LLM
pipeline rests on a *billing status*. Downgrading the API key — or a new key created in
haste, or a project that quietly falls off billing — would make the processing unlawful, and
**nothing in the code would notice.** That is a compliance control living outside the
codebase, which is the kind that fails silently. If this is ever worth hardening, the move is
to assert the tier at boot rather than to trust that nobody touches the console.

**Action:** confirm which tier `GEMINI_API_KEY` is on, and read the data-use terms attached
to it. If it is a free key — which is the default for a project at this stage, and therefore
the likely answer — **this is a launch blocker, and the fix is a credit card.**

---

## 4. Google Sign-In is not a processor, and that matters

When a user signs in with Google, Google is not processing on our instructions. It is
running its own authentication service, under its own terms, as an **independent
controller**. There is no Art. 28 DPA for it and none is needed.

What *is* needed: the privacy notice must tell users this happens, which
[`PRIVACY-NOTICE.md`](PRIVACY-NOTICE.md) §4 does. Our own basis for receiving the identity
back (`sub`, email, name, avatar → `social_accounts`) is Art. 6(1)(b) — the user asked to
log in this way.

Recording this because the reflex — "Google, therefore DPA" — produces the wrong analysis
here, and an incorrect register is worse than a thin one.

---

## 5. Open-Meteo — a genuine question with a trivial answer

**Today, Open-Meteo receives the user's precise coordinates.** `WeatherClient` caches the
*response* per H3 tile, but the request is
`?latitude={$session->origin->lat}&longitude={$session->origin->lng}`, at full precision
(`WeatherClient.php:61-66`, `:101-107`). DPIA §2.4 claims it receives "the coordinates of an
H3 tile". **That is not what the code does.**

That leaves an awkward question — is a free, non-commercial weather API a processor, does it
need a DPA, and what are its terms? — which is exactly the kind of question that generates
a week of work.

**Don't answer it. Delete it.** Send **the H3 cell's centroid** instead of the user's
position. The cache key is already the tile, so the response is *already* being reused
across every user in that ~0.7 km² cell — the precision is not buying anything. Weather does
not vary within 700 metres.

Once the payload is a tile centroid, **Open-Meteo receives no personal data**, and it drops
out of this register entirely — alongside Overpass and Wikidata.

This also kills **ROPA §9 B1**, the Pulse telemetry leak, at its source: the reason a slow
weather call can write a user's coordinates into `pulse_entries.key` is that the
coordinates are in the URL. Take them out of the URL and there is nothing to leak. *(You
should still set the Pulse `ignore` rules — defence in depth, and `UserRequests` still keys
by user id — but the coordinate problem goes away.)*

**One code change closes two findings and removes a vendor from the register.** It is the
highest-leverage item in any of these documents.

---

## 6. Where this list goes stale

The register is correct as of the commit it was written against. It goes wrong silently the
first time someone adds an `Http::` call to a new host.

**Make that a red build.** Every outbound call in this codebase goes through the `Http`
facade. A test asserting that the set of hosts the app can reach equals the set in §1 turns
"we added a vendor and forgot the paperwork" into a failing test — which is the only form of
documentation maintenance that actually happens. (Proposed alongside the schema tripwire in
ROPA §10.)

---

## 7. Summary — what is actually blocking launch

| | Item | Who | Blocking? |
|---|---|---|---|
| ~~1~~ | ~~Gemini tier / training terms (§3.2)~~ | — | **CLOSED 2026-07-12 — paid tier.** Re-check on any billing change. |
| **2** | **Hetzner DPA** (E1) | You — an errand | **Yes.** Everything is on it. |
| **3** | **Resend DPA** (E4) | You — an errand | **Yes.** It has every user's email. |
| **4** | **Google Maps DPA scope** (E2) | You — an errand | **Yes.** It gets precise position. |
| **5** | **Open-Meteo → tile centroid** (§5) | Dev — one line | Not legally blocking (intra-EEA), but it is the last precise coordinate going to a party with no DPA. |
| 6 | File the PDFs (E6) | You | Not blocking, but Art. 5(2) means undone = undemonstrable |

**Items 2–4 are the whole of what now stands between this and a defensible launch**, and none
of them can be delegated to any LLM: accepting a contract is an act of the controller. Each is
a login, a document, and a file.

---

## 8. Review

| Date | Version | Change | By |
|---|---|---|---|
| 2026-07-12 | 0.1 | First register, enumerated from the outbound-call inventory rather than from the DPIA — which is why it found Resend. | Claude (Opus 4.8), for the controller |
