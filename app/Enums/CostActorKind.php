<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * On whose behalf the money was spent (docs/COST.md §2.3).
 *
 * This exists because of a specific way cost data goes wrong. `DraftRegionPackJob`
 * and `BuildRegionWorldModelJob` spend real Gemini money on NOBODY's behalf — and if
 * you attribute that to whichever admin happened to click the button, one operator
 * "costs" €400 and every per-user number in the system is garbage. Region packs are
 * capex per REGION, amortised over that region's users forever; they are not a
 * person's usage.
 *
 * ADMIN §2.4 already required emulated-context costs to be flagged and excluded from
 * the trip-hour metric. This generalises that rule to every row of spend.
 *
 * Note what the split does NOT mean: the /admin cost strip shows the WHOLE bill,
 * every kind included, because the wallet does not care who spent it. Only *product*
 * metrics (cost per trip-hour, cost per recommendation) filter by actor.
 */
enum CostActorKind: string
{
    use HasOptions;

    /** A real person, doing the thing the product exists to do. */
    case User = 'user';

    /** An operator driving the pipeline from an emulated position (ADMIN §6). */
    case AdminEmulated = 'admin_emulated';

    /** Ingest, world-model builds, pack drafting. Capex, keyed by region. */
    case System = 'system';

    /** Speculative cache warming — spent before anyone asked for it. */
    case Warmer = 'warmer';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::AdminEmulated => 'Admin (emulated)',
            self::System => 'System / ingest',
            self::Warmer => 'Cache warmer',
        };
    }

    /** Product metrics count only real users (ADMIN §2.4). The wallet counts everything. */
    public function isProductSpend(): bool
    {
        return $this === self::User;
    }
}
