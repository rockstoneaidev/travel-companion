<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Models;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use Database\Factories\Domain\Opportunities\OpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A short-lived, context-bound moment (PROPRIETARY-SHELL ZONE). Note the
 * naming rule: the table's short-livedness is generic; `ephemeral` is one
 * OpportunityKind value (PRD §14.2).
 */
#[UseFactory(OpportunityFactory::class)]
final class Opportunity extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => OpportunityKind::class,
            'status' => OpportunityStatus::class,
            'friction' => 'array',
            'window_starts_at' => 'immutable_datetime',
            'window_ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(OpportunityEvidence::class);
    }
}
