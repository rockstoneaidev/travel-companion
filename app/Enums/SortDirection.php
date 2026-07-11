<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Cross-module: every list endpoint's `sort_dir` (conventions/07).
 */
enum SortDirection: string
{
    use HasOptions;

    case Asc = 'asc';
    case Desc = 'desc';

    public function label(): string
    {
        return match ($this) {
            self::Asc => 'Ascending',
            self::Desc => 'Descending',
        };
    }
}
