<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Trips\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            // The token is NOT echoed back. It is the one field here that is a secret and a
            // personal identifier at once, and a payload that repeats it is a payload that
            // will end up in a log.
        ];
    }
}
