<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Models\Device;
use Carbon\CarbonImmutable;

/**
 * "Stop sending to this phone." — sign-out, uninstall, or the user simply saying no.
 *
 * Revoked, never deleted. A deleted row cannot explain a silence, and "we stopped being
 * able to reach this person on 3 August" is a fact worth having when somebody asks why
 * they got no pushes on the 4th. Retention takes it later; we do not take it now.
 */
final class RevokeDevice
{
    public function __invoke(Device $device): Device
    {
        if ($device->isLive()) {
            $device->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        }

        return $device;
    }
}
