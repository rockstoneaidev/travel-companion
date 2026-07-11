<?php

declare(strict_types=1);

namespace App\Domain\Sources\Models;

use App\Domain\Sources\Exceptions\StoragePolicyViolation;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Enums\StoragePolicy;
use Database\Factories\Domain\Sources\SourceItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A raw normalized candidate from one source — EVIDENCE-STORE ZONE, each row
 * carrying its own license metadata (conventions/03).
 *
 * The boot guard is the StoragePolicy boundary made structural: EdgeOnly data
 * (Google & friends) can never be persisted as a source item. It throws — it
 * does not warn (conventions/09).
 */
#[UseFactory(SourceItemFactory::class)]
final class SourceItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'license' => SourceLicense::class,
            'storage_policy' => StoragePolicy::class,
            'credibility_tier' => CredibilityTier::class,
            'payload' => 'array',
            'retrieved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $item): void {
            if (! $item->storage_policy->isStorable()) {
                throw StoragePolicyViolation::edgeOnlyPersistence($item->source);
            }
        });
    }
}
