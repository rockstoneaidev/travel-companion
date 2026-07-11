# Engineering conventions

**Audience: every developer on this repo, human or LLM.**

`docs/PRD.md`, `docs/DATA-SOURCES.md` and `docs/ODBL-REVIEW.md` say *what* to build and *why*.
The documents in this directory say *how the code is shaped*. They exist so that two developers
who have never spoken write code that looks like it came from one hand.

These are not suggestions. If a task appears to require breaking one of these conventions, say so
explicitly in the PR or the conversation — do not quietly deviate.

## Reading order

Read `01` before writing any code. After that, read the document that matches what you're touching.

| # | Document | Read when |
|---|----------|-----------|
| [01](01-domain-modules.md) | Domain modules & layering | **Always.** Defines where every class lives and who may call whom. |
| [02](02-enums.md) | Enums | Any fixed set of values — status, kind, category, tier. |
| [03](03-migrations-and-schema.md) | Migrations & schema | Any migration. Encodes the ODbL licensing boundary as schema rules. |
| [04](04-controllers-and-routing.md) | Controllers & routing | Any HTTP entry point (Inertia or `/api/v1`). |
| [05](05-validation-and-requests.md) | Validation & form requests | Any request that carries input. |
| [06](06-resources-and-serialization.md) | Resources & serialization | Any data leaving the backend, to React or to a client. |
| [07](07-pagination-filtering-sorting.md) | Pagination, filtering, sorting | Any endpoint returning a list. |
| [08](08-jobs-and-queues.md) | Jobs & queues | Anything under `app/Jobs/`. |
| [09](09-source-adapters.md) | Source adapters | Any new data source. Licensing is load-bearing here. |
| [10](10-llm-usage.md) | LLM usage | Any call to a model. |
| [11](11-testing.md) | Testing | Always, eventually. |
| [12](12-caching-and-tiles.md) | Caching & tile keys | Anything cached, especially scout results. |

## These rules are enforced, not just written down

`tests/Arch/ConventionsTest.php` turns the structural rules below into failing builds — the module
boundary, transport-agnostic domain code, thin controllers and jobs, string-backed enums, readonly
DTOs, strict types. Run them with `vendor/bin/pest --testsuite=Arch` (sub-second).

When you add a convention here, ask whether it can be an `arch()` rule. If it can, it should be —
a rule a reviewer has to remember is a rule that gets broken.

## The five rules that outrank everything else

If you remember nothing else from these documents:

1. **Business logic lives in `app/Domain/`.** Controllers, jobs and commands are thin wrappers.
   Inertia is a delivery layer, not an API. ([01](01-domain-modules.md))
2. **`places_core` contains only ODbL-compatible open data.** Proprietary value lives in separate
   tables keyed by `place_id`. ([03](03-migrations-and-schema.md), ODBL-REVIEW §6)
3. **Google Places data is never persisted.** Edge-only. Store the `place_id` and nothing else.
   ([09](09-source-adapters.md))
4. **The LLM is never a source of facts.** It generates only from a stored evidence bundle, and
   every generation records `prompt_version`. ([10](10-llm-usage.md))
5. **Version everything and store the decision trace.** Every recommendation records why it existed.
   ([08](08-jobs-and-queues.md), PRD §15)

## Status

Written 2026-07-11, against Laravel 13 / PHP 8.5, before feature work started. The code examples
here describe classes that do not exist yet — they are the target shape, not a description of what
is in the repo today. Update the document in the same PR that breaks its rule.
