<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The three kinds of cost (docs/COST.md §2.1), and they are NOT the same number.
 *
 * `Llm` and `Api` are metered, per-event, externally priced and causally
 * attributable. Real money, a real owner: a ledger.
 *
 * `Compute` is not. CPU and memory are rented in advance in fixed lumps — you pay
 * Hetzner the same whether or not a request happens, so there is no marginal price
 * to record. Compute rows therefore carry UNITS (`cpu_ms`) and a zero
 * `billed_usd_micros`, always. The infra bill is divided across measured units at
 * report time (COST.md §7.1). Putting money on a compute row would be fake
 * precision, and fake precision in a cost model is worse than a gap, because a gap
 * is visible.
 */
enum CostCategory: string
{
    use HasOptions;

    case Llm = 'llm';
    case Api = 'api';
    case Compute = 'compute';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM generation',
            self::Api => 'External API',
            self::Compute => 'Compute',
        };
    }

    /** Compute is measured, never priced — see the class docblock. */
    public function isBillable(): bool
    {
        return $this !== self::Compute;
    }
}
