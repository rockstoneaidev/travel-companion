<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Contracts\PushSender;
use App\Domain\Notifications\Data\PushMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The development rail: it writes the push down and sends nothing.
 *
 * The DEFAULT, and deliberately so. A misconfigured environment that silently sends real
 * notifications to real phones is a far worse failure than one that silently sends none —
 * so the fallback is the safe one, and reaching FCM requires saying so out loud in config.
 */
final class LogPushSender implements PushSender
{
    public function send(PushMessage $message): ?string
    {
        Log::info('push (not actually sent — LogPushSender)', [
            'notification_id' => $message->notificationId,
            'platform' => $message->platform->value,
            'title' => $message->title,
            'deep_link' => $message->deepLink,
            // The token is a credential. It is not written to a log, here or anywhere.
        ]);

        return 'log-'.Str::uuid()->toString();
    }
}
