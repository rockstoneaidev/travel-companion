<?php

declare(strict_types=1);

namespace App\Domain\Curation\Models;

use App\Domain\Curation\Enums\CurationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One curated claim (CURATION §2) — proprietary, evidence-grounded, review-gated. */
final class CuratedItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CurationStatus::class,
            'facets' => 'array',
            'evidence' => 'array',
            'verdict' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
