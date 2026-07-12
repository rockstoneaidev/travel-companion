# Signed processor agreements (Art. 28)

**This directory is the filing cabinet, and it is currently empty.**

Art. 5(2) is the accountability principle: the controller must be able to **demonstrate**
compliance. "I'm sure I clicked accept" is not a demonstration. A DPA you cannot produce on
request is, for evidentiary purposes, a DPA you do not have.

So: one PDF per processor, named `<processor>-dpa-<YYYY-MM-DD>.pdf`. Commit them.

## What belongs here

Nothing on this list can be done by an LLM. Accepting a contract is an act of the
controller, and no agent can perform it on your behalf. [`../PROCESSORS.md`](../PROCESSORS.md)
tells you what to sign and why; **signing is what closes DPIA §7.5, not writing the register.**

| | Processor | What it holds | Where to get it | Filed? |
|---|---|---|---|---|
| **1** | **Hetzner** | Everything — it is the database | AVV / DPA in the Robot or Cloud console | ☐ |
| **2** | **Google Cloud / Maps Platform** | **Precise user coordinates** (Routes) | Cloud DPA — **confirm it covers Maps Platform, which is a different product from Cloud** | ☐ |
| **3** | **Google Gemini API** | Place evidence + city + time of day | Gemini API terms — **and settle the training question first, see below** | ☐ |
| **4** | **Resend** | Every user's **email address** | resend.com/legal/dpa — click-to-accept in the dashboard | ☐ |

Resend is on this list because the outbound-call inventory found it and the DPIA had never
mentioned it. You cannot go and sign the right agreements if the vendor list is incomplete —
which is the actual reason the register was worth writing.

## Before you sign #3

**Confirm which Gemini tier `GEMINI_API_KEY` is on.** On the free tier Google generally uses
prompts and responses to improve its products; on the paid tier it generally does not.

If we are on a free key — which is the default at this stage, and therefore the likely answer
— then place evidence, city and part-of-day are being processed **for Google's purposes, not
ours**. That is a processor acting outside the controller's instructions (Art. 28(3)(a)), for
a purpose disclosed to nobody. It is the one genuine launch blocker in the legal set, and the
fix is a credit card rather than a code change.

## While you are in each console

Two things the breach procedure needs and cannot get anywhere else
([`../BREACH-PROCEDURE.md`](../BREACH-PROCEDURE.md) §7):

- **How does this processor notify *us* of a breach?** Art. 33(2) obliges them to, without
  undue delay — but only to the contact they have on file.
- **Which inbox does that land in?** A notification sent to an address nobody reads is the
  72-hour clock running while you sleep.
