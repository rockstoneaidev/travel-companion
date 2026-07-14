<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationGate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A decision to interrupt somebody — or, far more often, a decision not to.
 *
 * The denials are the point. "We considered telling you about the market and did not,
 * because you were driving" is a more useful record than the market never appearing, and
 * it is the only thing that makes PRD §12.2's counterfactual askable at all.
 */
final class Notification extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
            'denied_by' => NotificationGate::class,
            'priority' => 'float',
            'trace' => 'array',
            'sent_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }
}
