# 06 — Resources & serialization

Nothing leaves the backend as a raw model. Not to the API, not to Inertia props.

The reason is not aesthetics. A model serialized directly is a **contract you did not choose**: add
a column next month and it silently ships to every client, including columns that must never be
exposed (raw location, a Google-derived field, an internal score). An API Resource is an explicit,
reviewable list of what is public.

## Location & naming

```
app/Http/Resources/
    Api/V1/TripResource.php                 App\Http\Resources\Api\V1
    Api/V1/OpportunityResource.php
    Api/V1/RecommendationResource.php
```

`{Subject}Resource`, singular. Collections use `TripResource::collection($trips)` — write a
dedicated `TripCollection` class only when the collection itself needs metadata that the paginator
doesn't already provide.

**Inertia props use the same Resource classes.** A page's props are
`['trips' => TripResource::collection($trips)]`, not a hand-built array. One shape, one place to
change it, and the mobile client in Phase 2 sees exactly what the web client sees.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Trips\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Trip */
final class TripResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'status'     => $this->status->value,          // enum → its backing value
            'starts_on'  => $this->starts_on->toDateString(),
            'ends_on'    => $this->ends_on->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),

            'segments'   => TripSegmentResource::collection($this->whenLoaded('segments')),
            'stats'      => new TripStatsResource($this->whenLoaded('stats')),
        ];
    }
}
```

## Rules

- **`/** @mixin Model *\/`** on the class. Without it, every `$this->foo` is an IDE and static-analysis
  blind spot.
- **Explicit field list.** Never `parent::toArray($request)`, never `$this->resource->toArray()`.
  That is the raw-model leak wearing a Resource's clothes.
- **`snake_case` keys** on the wire, matching the database and the request payloads. The React side
  converts if it wants to; do not mix conventions across the boundary.
- **Enums serialize as `->value`**, a plain string. If the UI needs the label, ship
  `{'value': 'active', 'label': 'Active'}` — but prefer sending `options()` once for the picker and
  the bare value on the entity.
- **Dates as ISO-8601 with the offset** (`toIso8601String()`), or a bare date string for date-only
  fields. Never a raw `Carbon` dump, never a Unix timestamp. This is a travel product across
  timezones; ambiguity here becomes a bug in a French vineyard at 19:00.
- **`whenLoaded()` for every relation.** A Resource must never trigger a query. If
  `$this->segments` lazy-loads, you have an N+1 that only fires in production under load. Eager-load
  in the Query object, expose with `whenLoaded`.
- **No conditional business logic.** `$this->when($user->isPremium(), ...)` for visibility is fine;
  computing a score inside `toArray()` is not.
- **Never expose:** raw location points (only what the privacy layer permits — PRD §16), internal
  sub-scores unless the endpoint is explicitly the trace endpoint, `*_version` fields on
  user-facing resources, or **any Google-derived field** ([03](03-migrations-and-schema.md)).

## Attribution is part of the payload

Non-negotiable, from ODBL-REVIEW §6 rule 6: anything derived from an attributed source ships its
attribution with it. A place resource carries its source attribution; an evidence excerpt carries
its license. This is not a footer the frontend invents — it comes from the data.

```php
'attribution' => AttributionResource::collection($this->whenLoaded('sources')),
```

## Frontend types

Every Resource has a TypeScript counterpart in `resources/js/types/`. The TS type is the mirror of
`toArray()`, and it is the type of the Inertia page prop.

```ts
// resources/js/types/trip.ts
export interface Trip {
  id: string;
  title: string;
  status: TripStatus;          // from types/enums.ts — see 02-enums.md
  starts_on: string;
  ends_on: string;
  created_at: string;
  segments?: TripSegment[];    // optional: mirrors whenLoaded()
}
```

Relations exposed via `whenLoaded()` are **optional** in the TS type. That optionality is
information — it tells the frontend developer the field may be absent, which is exactly true.

## Checklist

- [ ] Explicit field list. No `parent::toArray()`.
- [ ] `@mixin` annotation present.
- [ ] Every relation behind `whenLoaded()`; the Query eager-loads them.
- [ ] Enums as values, dates as ISO-8601.
- [ ] Nothing Google-derived, no raw location, no internal scores.
- [ ] Attribution included where the source requires it.
- [ ] TS mirror updated in the same PR.
