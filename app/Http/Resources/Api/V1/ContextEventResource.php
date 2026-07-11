<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Context\Models\ContextEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContextEvent
 *
 * The acknowledgement of a recorded observation. It deliberately does not echo
 * the location back: the client already has it, and a raw coordinate that need
 * not cross the wire should not (PRD §16).
 */
final class ContextEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'explore_session_id' => $this->explore_session_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'movement_mode' => $this->movement_mode?->value,
            'app_state' => $this->app_state?->value,
            'available_minutes' => $this->available_minutes,
            'has_location' => $this->location !== null,
        ];
    }
}
