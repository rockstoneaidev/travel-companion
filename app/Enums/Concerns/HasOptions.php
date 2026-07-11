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
