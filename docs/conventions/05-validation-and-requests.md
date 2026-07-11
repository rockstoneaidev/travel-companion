# 05 — Validation & form requests

Every request carrying input gets a Form Request. No exceptions, including the "it's only one
field" ones — the exception is where the drift starts.

A Form Request does three jobs: **authorize**, **validate**, and **hand the domain a DTO**. That
third job is the one most codebases skip, and it is the one that keeps controllers thin.

## Location & naming

```
app/Http/Requests/
    Api/V1/Trips/StoreTripRequest.php       App\Http\Requests\Api\V1\Trips
    Api/V1/Trips/UpdateTripRequest.php
    Web/Trips/StoreTripRequest.php          only if the web form genuinely differs
```

Named `{Action}{Subject}Request` — `StoreTripRequest`, `UpdateTripRequest`, `IndexTripRequest`.

**Share the request class between the Inertia and API controllers whenever the input is the same** —
which it usually is, because both call the same action. Only fork into `Web/` when the surfaces
genuinely accept different fields.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Trips;

use App\Domain\Trips\Data\NewTripData;
use App\Domain\Trips\Enums\TripStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Trip::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:120'],
            'status'        => ['required', Rule::enum(TripStatus::class)->only([TripStatus::Draft, TripStatus::Planned])],
            'starts_on'     => ['required', 'date', 'after_or_equal:today'],
            'ends_on'       => ['required', 'date', 'after:starts_on'],
            'home_timezone' => ['required', 'timezone'],
            'interests'     => ['sometimes', 'array', 'max:10'],
            'interests.*'   => [Rule::enum(InterestTag::class)],
        ];
    }

    /** The only thing the controller calls. Validated input crosses into the domain as a DTO. */
    public function toData(): NewTripData
    {
        return new NewTripData(
            userId:       $this->user()->id,
            title:        $this->string('title')->toString(),
            status:       $this->enum('status', TripStatus::class),
            startsOn:     CarbonImmutable::parse($this->date('starts_on')),
            endsOn:       CarbonImmutable::parse($this->date('ends_on')),
            homeTimezone: $this->string('home_timezone')->toString(),
            interests:    $this->collect('interests')->map(InterestTag::from(...))->all(),
        );
    }
}
```

Note `$this->enum()` and `$this->date()` — Laravel's typed accessors. Do not pull
`$request->validated()['status']` out as a string and re-parse it downstream.

## Rules

- **Array syntax, always.** `['required', 'string']`, never `'required|string'`. Pipe strings break
  the moment a rule contains a `|` (a regex, a `Rule::` object).
- **`Rule::enum()`** for every enum field ([02](02-enums.md)). Use `->only()` to restrict which
  cases *this endpoint* accepts — a client should not be able to `POST` a trip straight into
  `completed`.
- **Bound every string** with `max:`. An unbounded `string` is a memory and storage vector.
- **`exists:` rules must be scoped to the user** where the resource is user-owned:
  `Rule::exists('trips', 'id')->where('user_id', $this->user()->id)`. An unscoped `exists:` is an
  IDOR waiting to happen.
- **Coordinates:** validate ranges explicitly (`numeric`, `between:-90,90` / `between:-180,180`).
  A swapped lat/lng that passes validation produces a place in the ocean and a very confusing bug.
- Put a `prepareForValidation()` in only for normalization (trimming, lowercasing an email), never
  for defaulting business values — defaults are the domain's job.

## Authorization

`authorize()` returns a bool or (better) delegates to a Policy. Do not return `true` reflexively.
If a request genuinely needs no authorization beyond `auth:sanctum`, write `return true;` and mean
it — but for anything trip-scoped, check ownership.

A failed `authorize()` yields a 403 automatically; do not throw by hand.

## Messages

Override `messages()` only where the default is genuinely unhelpful to an end user. Do not restate
the rule ("The title field is required" adds nothing). `attributes()` is often the better lever:
rename `home_timezone` to "home timezone" once, and every message reads properly.

## Query-parameter validation

List endpoints validate their query string too — same mechanism, an `IndexTripRequest` with
`page`, `per_page`, `sort_by`, filters. See [07](07-pagination-filtering-sorting.md), which defines
the exact rule set. An unvalidated `sort_by` reaching a query builder is a SQL injection.

## Checklist

- [ ] One Form Request per input-carrying endpoint, shared across Inertia/API where the input is.
- [ ] `authorize()` actually authorizes.
- [ ] Array rule syntax; every string bounded; every enum via `Rule::enum()`.
- [ ] `exists:` scoped to the owner.
- [ ] `toData()` returns a DTO — the controller never touches `validated()`.
