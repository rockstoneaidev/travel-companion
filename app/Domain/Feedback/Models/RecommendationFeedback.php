<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Models;

use App\Domain\Feedback\Enums\FeedbackEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * One feedback event — the moat, row by row (PRD §14.5). Holds
 * recommendation_id as a plain key (conventions/01: no cross-module models).
 */
final class RecommendationFeedback extends Model
{
    protected $table = 'recommendation_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => FeedbackEvent::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
