<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Data\NewDeviceData;
use App\Domain\Trips\Models\Device;
use Carbon\CarbonImmutable;

/**
 * "This is my phone; you may reach me here." (E29; PRD §8.2.)
 *
 * An UPSERT on the token, not an insert, and that is the whole subtlety. FCM and APNs
 * reissue tokens — on reinstall, on OS upgrade, on their own schedule — and the same
 * physical phone will present a new one and, later, present an old one again. Inserting
 * blindly gives one person four rows, and four rows is the same notification four times,
 * which is precisely the fatigue PRD §12 exists to prevent.
 *
 * A token that reappears is also un-revoked: the phone is evidently back.
 */
final class RegisterDevice
{
    public function __invoke(NewDeviceData $data): Device
    {
        $device = Device::query()->firstOrNew(['push_token' => $data->pushToken]);

        $device->forceFill([
            'user_id' => $data->userId,
            'platform' => $data->platform,
            'app_version' => $data->appVersion,
            'last_seen_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ])->save();

        return $device;
    }
}
