<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Trips\Enums\DevicePlatform;

final readonly class PushMessage
{
    public function __construct(
        public string $pushToken,
        public DevicePlatform $platform,
        public string $title,
        public string $body,
        /**
         * The deep link — into the DETAIL screen, never the feed.
         *
         * A push that says "the market closes in 22 minutes" and lands you on a list is a
         * push that has wasted the interruption it just spent (PRD §12.3).
         */
        public string $deepLink,
        /** Correlation, so the receipt can find its way home. */
        public string $notificationId,
    ) {}
}
