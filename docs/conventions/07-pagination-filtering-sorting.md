# 07 — Pagination, filtering & sorting

Every list endpoint paginates. The only exemptions are sets that are bounded by construction and
small — enum options, a trip's own segments, the sources in the registry. If the row count grows
with usage, it paginates.

## Choosing a style

| Style | Params | Use for | Builder |
|-------|--------|---------|---------|
| **Offset** (default) | `page`, `per_page` | Admin/curation tables, anything with "go to page 4", anything needing a total | `->paginate()` |
| **Cursor** | `cursor`, `per_page` | Append-only, time-ordered feeds: `context_events`, traces, notification history | `->cursorPaginate()` |

Cursor pagination requires ordering on a **unique, indexed** key — in practice `(created_at, id)`.
Offset pagination on a hot append-only table produces duplicate and skipped rows as new events land
between page fetches, which is exactly the feed case, which is why the feeds are cursor-based.

## Where the query lives

**In a Query object, not the controller** ([01](01-domain-modules.md)).

```php
namespace App\Domain\Trips\Queries;

final readonly class ListTrips
{
    public function __invoke(ListTripsCriteria $criteria): LengthAwarePaginator
    {
        return Trip::query()
            ->where('user_id', $criteria->userId)
            ->when($criteria->statuses, fn ($q, $s) => $q->whereIn('status', array_column($s, 'value')))
            ->when($criteria->search, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('title', 'ilike', "%{$term}%")
                  ->orWhere('destination', 'ilike', "%{$term}%");
            }))
            ->with(['segments'])                                    // no N+1 in the Resource
            ->orderBy($criteria->sortBy->column(), $criteria->sortDir->value)
            ->orderBy('id')                                         // stable tiebreak — always
            ->paginate($criteria->perPage);
    }
}
```

The controller calls `$listTrips($request->toCriteria())` and wraps the result in a Resource.

## Request parameters

Validated in the Index Form Request ([05](05-validation-and-requests.md)):

```php
public function rules(): array
{
    return [
        'page'      => ['sometimes', 'integer', 'min:1'],
        'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        'sort_by'   => ['sometimes', Rule::enum(TripSortField::class)],   // ← whitelist as an enum
        'sort_dir'  => ['sometimes', Rule::enum(SortDirection::class)],
        'q'         => ['sometimes', 'string', 'max:120'],
        'status'    => ['sometimes', 'array'],
        'status.*'  => [Rule::enum(TripStatus::class)],
    ];
}
```

**`sort_by` is an enum, never a free string.** A raw `sort_by` interpolated into `orderBy()` is a
SQL injection, and `Rule::in([...])` on a list of column names duplicates knowledge that an enum
holds properly:

```php
enum TripSortField: string
{
    use HasOptions;

    case StartsOn  = 'starts_on';
    case CreatedAt = 'created_at';
    case Title     = 'title';

    /** Maps the public sort name to the actual column. They are allowed to differ. */
    public function column(): string
    {
        return match ($this) {
            self::StartsOn  => 'starts_on',
            self::CreatedAt => 'created_at',
            self::Title     => 'title',
        };
    }
}
```

Defaults: `per_page` **25**, hard cap **100**. The cap is not advisory — `per_page=100000` is a
denial-of-service against our own Postgres.

## Response shape

### JSON API (`/api/v1`)

Laravel's paginator already emits `data` / `links` / `meta` when a Resource collection wraps it.
**Use that.** Do not hand-roll an envelope.

```php
return TripResource::collection($listTrips($criteria));
```

```json
{
  "data": [ /* TripResource[] */ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta":  { "current_page": 1, "per_page": 25, "total": 137, "last_page": 6, "path": "..." }
}
```

Where the client needs to know the *applied* filters (to render active filter chips), add them
under `meta` with `additional()`:

```php
return TripResource::collection($paginator)->additional([
    'meta' => ['filters' => $criteria->toArray()],
]);
```

Cursor pagination emits `meta.next_cursor` / `meta.prev_cursor` and **no total** — that is correct
and expected, not a gap to paper over. Counting an append-only feed is an expensive lie.

### Inertia

Pass the paginator straight into props. Inertia + the frontend read the same `data`/`links`/`meta`
shape, so there is one contract, not two:

```php
return Inertia::render('trips/index', [
    'trips'   => TripResource::collection($listTrips($criteria)),
    'filters' => $criteria->toArray(),
]);
```

Use Inertia 2's `WhenVisible` / deferred props for heavy secondary payloads rather than fattening
the list response.

## Performance rules

- **Every filterable and sortable column is indexed** ([03](03-migrations-and-schema.md)). A
  `sort_by` enum with an unindexed column behind it is a slow query that passed code review.
- **Always add a stable tiebreak** (`->orderBy('id')`). Without it, two rows with the same
  `starts_on` can swap between page 1 and page 2 and the user sees a duplicate.
- **No heavy children in a list.** A recommendation's full decision trace, an opportunity's evidence
  bundle, a place's photos — these are `show`-endpoint payloads or a lazy second fetch. A list
  response that carries them will be the slowest endpoint we own.
- **Eager-load in the Query**, and let the Resource use `whenLoaded()`. N+1 in a paginated list is
  25 extra queries per page.
- **Geo lists** filter by tile or bounding box *before* ordering by distance. Never order the whole
  table by `ST_Distance` and then paginate.
- Count queries on large tables are expensive; if `total` isn't rendered, use `simplePaginate()`.

## Frontend expectations

- Pagination, sort and filter state lives **in the URL query string** — deep-linkable, back-button
  correct, shareable. Not in component state.
- Debounce free-text search 250–400 ms before issuing the request.
- Reset to `page=1` whenever a filter or the search term changes. Landing on page 4 of a
  freshly-filtered 2-page result is the classic bug here.

## Checklist

- [ ] The list paginates, with the right style for the access pattern.
- [ ] The query lives in a Query object, not the controller.
- [ ] `sort_by` is an enum; `per_page` is capped at 100.
- [ ] There is a stable tiebreak in the ordering.
- [ ] Every sorted/filtered column is indexed.
- [ ] Relations eager-loaded; no N+1 in the Resource.
- [ ] Standard Laravel envelope — no hand-rolled `meta`.
- [ ] A test asserts `meta.total` / `meta.next_cursor` and the page size cap.
