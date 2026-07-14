<?php

declare(strict_types=1);

namespace App\Domain\Trips\Models;

use App\Domain\Trips\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A phone we are allowed to interrupt (E29; PRD §8.2).
 *
 * The push token is the address of someone's pocket, so this is a personal-data table
 * (ROPA §4.1): it cascades on account deletion and it comes back in the export.
 */
final class Device extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** A device we may actually send to. */
    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }
}
