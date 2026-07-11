<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Models;

use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence bundle row (EVIDENCE-STORE ZONE) — per-row license metadata. */
final class OpportunityEvidence extends Model
{
    protected $table = 'opportunity_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'license' => SourceLicense::class,
            'credibility_tier' => CredibilityTier::class,
            'retrieved_at' => 'immutable_datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
