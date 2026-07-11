# 02 — Enums

Any time a column, request field or DTO property holds one of a fixed set of values, that set is a
**native PHP backed enum**. Not a string constant, not a class of `public const`, not a database
`ENUM` type, not a lookup table.

> The product **categorisation taxonomy** — `PlaceTypeDomain`, `PlaceType` (Places module) and the
> cross-module `AppealFacet` — is the largest concrete instance of this convention. Its values,
> source mappings, and versioning are specified in [TAXONOMY.md](../TAXONOMY.md) §6; this document
> governs how they are shaped in code.

## The two halves of the rule

1. **In PHP: a native backed enum.** `enum TripStatus: string`.
2. **In the database: a plain `varchar`.** Never PostgreSQL's native `ENUM` type.

The second half matters as much as the first. A native Postgres enum type requires an `ALTER TYPE`
migration to add a value, cannot easily remove one, and reorders badly under `pg_dump`. A `varchar`
column plus a PHP enum gives the same safety at the only boundary that can actually enforce it —
the application — with none of the migration pain. Deployment is a code deploy, not a schema
migration.

## Location & naming

- **Path:** `app/Domain/{Module}/Enums/{Name}.php`
- **Namespace:** `App\Domain\{Module}\Enums`
- **Truly cross-module enums only:** `app/Enums/`, namespace `App\Enums`. Be strict about this —
  if only one module uses it, it belongs to that module. `SourceLicense` is cross-module;
  `TripStatus` is not.

Names are **singular PascalCase, no suffix**. `TripStatus`, not `TripStatusEnum` or `TripStatusType`.
The type system already tells you it is an enum.

```
app/Domain/Trips/Enums/TripStatus.php               App\Domain\Trips\Enums\TripStatus
app/Domain/Opportunities/Enums/OpportunityKind.php  App\Domain\Opportunities\Enums\OpportunityKind
app/Enums/SourceLicense.php                         App\Enums\SourceLicense
```

## Backing type

**Always `string`-backed.** Not pure (unbacked) — a pure enum cannot be persisted or serialized.
Not `int`-backed — integer backing makes the database unreadable and the JSON API opaque, and the
storage saving is irrelevant at our scale.

Values are `snake_case`, stable, and **never renamed after they reach production** — a rename is a
data migration. Choose the value as carefully as you would a column name.

## Skeleton

```php
<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

enum TripStatus: string
{
    use HasOptions;

    case Draft     = 'draft';
    case Planned   = 'planned';
    case Active    = 'active';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    /** Human-readable label. Translate at the edge, not here, if we ever localize the UI. */
    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Planned   => 'Planned',
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
        };
    }

    /** Behaviour belongs on the enum when it is a property of the value itself. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Completed, self::Abandoned];
    }
}
```

Case names are **PascalCase**; values are **snake_case**. Yes, they differ — the case name is PHP,
the value is data.

### The shared trait

One trait, `app/Enums/Concerns/HasOptions.php`, gives every enum the three things that would
otherwise be copy-pasted:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

trait HasOptions
{
    /** @return list<string> — for validation rules and tests. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> — for frontend selects. */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : $case->name,
            ],
            self::cases(),
        );
    }
}
```

That is the whole helper surface. Do **not** reintroduce a `from()` that returns a bool, or a
static `toString($value)` — PHP gives you `TripStatus::from()`, `::tryFrom()`, `::cases()` and
`$case->label()`, and shadowing the built-ins is how you get subtly wrong code.

## Using them

### Model casts

```php
protected function casts(): array
{
    return [
        'status' => TripStatus::class,
        'kinds'  => AsEnumCollection::of(OpportunityKind::class),   // for a json array column
    ];
}
```

A cast enum column is **never** read as a raw string elsewhere in the app. `$trip->status` is a
`TripStatus`, and `$trip->status === TripStatus::Active` is the only correct comparison. If you find
yourself writing `$trip->status === 'active'`, the cast is missing.

### Validation

```php
use Illuminate\Validation\Rule;

'status' => ['required', Rule::enum(TripStatus::class)],

// Restrict which cases this particular endpoint accepts:
'status' => ['required', Rule::enum(TripStatus::class)->only([TripStatus::Planned, TripStatus::Active])],
```

Never `Rule::in(TripStatus::values())` when `Rule::enum()` will do — `Rule::enum()` hydrates the
validated value into an enum instance for you. Reach for `values()` only where a rule needs a raw
list (e.g. inside an `array` rule's `*` element in an older-style rule string).

### Route binding

Enums bind implicitly in routes:

```php
Route::get('/trips/status/{status}', fn (TripStatus $status) => /* ... */);
```

An invalid value produces a 404 before your controller runs. Use this rather than validating a
route segment by hand.

### Comparisons and exhaustiveness

Use `match` without a `default` arm when switching on an enum. When a new case is added later, PHP
throws `UnhandledMatchError` and the missing branch surfaces immediately. A `default` arm silently
swallows exactly the bug you want to find.

## Frontend parity

The React side must not hand-type `'draft' | 'planned' | ...` string unions that drift.

- Mirror every enum that crosses the wire into `resources/js/types/enums.ts`.
- Where the UI renders a picker, ship the options from the backend (`TripStatus::options()`) via
  Inertia props or an API resource rather than duplicating labels in TypeScript.
- **A test asserts parity** between the PHP cases and the TS union
  (`tests/Feature/EnumParityTest.php`). Until that test exists, drift is a matter of time.

```ts
// resources/js/types/enums.ts
export const TRIP_STATUS = ['draft', 'planned', 'active', 'completed', 'abandoned'] as const;
export type TripStatus = (typeof TRIP_STATUS)[number];
```

## Database

See [03-migrations-and-schema.md](03-migrations-and-schema.md). In short:

```php
// ✅
$table->string('status', 32)->index();

// ❌ never
$table->enum('status', ['draft', 'planned']);   // creates a native Postgres enum type
```

Add a `CHECK` constraint only where an invalid value would be actively dangerous (e.g. a licensing
class on a `places_core` row — see ODBL-REVIEW §6). Everywhere else, the cost of the constraint
(a migration every time a case is added) is not worth the protection, because the only writer is
this application and this application has the enum.

Set a default in the migration **and** as a property default on the model, so a hand-inserted row
and an `Model::create()` agree.

## Checklist

- [ ] Native backed enum, `string`, in `app/Domain/{Module}/Enums/`.
- [ ] `HasOptions` trait; `label()` where a human reads it.
- [ ] Column is `varchar`, not a native enum type.
- [ ] Model casts it. Nothing compares it to a raw string.
- [ ] Validation uses `Rule::enum()`.
- [ ] `match` on it has no `default` arm.
- [ ] If it crosses the wire, it is mirrored in `resources/js/types/enums.ts`.
