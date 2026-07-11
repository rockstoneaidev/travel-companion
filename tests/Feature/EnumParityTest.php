<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;

/*
|--------------------------------------------------------------------------
| Enum parity — docs/conventions/02-enums.md
|--------------------------------------------------------------------------
|
| Every PHP enum that crosses the wire is mirrored as a const array in
| resources/js/types/enums.ts. This test is what keeps the two from drifting.
|
*/

$tsConstValues = function (string $constant): array {
    $source = file_get_contents(resource_path('js/types/enums.ts'));

    preg_match("/export const {$constant} = \\[(.*?)\\] as const/s", $source, $matches);

    expect($matches)->not->toBeEmpty("const {$constant} not found in enums.ts");

    return array_values(array_filter(array_map(
        fn (string $value): string => trim(trim($value), "'\""),
        explode(',', $matches[1]),
    )));
};

it('mirrors Role in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('ROLES'))->toBe(Role::values());
});

it('mirrors Permission in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('PERMISSIONS'))->toBe(Permission::values());
});
