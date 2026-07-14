<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Trips\Enums\DevicePlatform;

final readonly class NewDeviceData
{
    public function __construct(
        public int $userId,
        public DevicePlatform $platform,
        public string $pushToken,
        public ?string $appVersion = null,
    ) {}
}
