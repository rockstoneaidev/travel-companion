<?php

declare(strict_types=1);

namespace App\Domain\Context\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The app state the context event was observed in (PRD §14.5 — `app_state`).
 *
 * Phase 1 is **foreground-only** (PRD §8): the client never reports
 * `background`, because there is no background location collection. The case
 * exists because the wire payload is defined that way and Phase 2 will use it —
 * it is a field, not machinery. Nothing in Phase 1 branches on it.
 */
enum AppState: string
{
    use HasOptions;

    case Foreground = 'foreground';
    case Background = 'background';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
