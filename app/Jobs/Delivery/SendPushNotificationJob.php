<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Domain\Notifications\Contracts\PushSender;
use App\Domain\Notifications\Data\PushMessage;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Trips\Models\Device;
use App\Enums\QueueLane;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Get an approved notification to a handset (E31; PRD §12.3).
 *
 * On the `realtime` lane — the one conventions/08 defined and nothing has ever used. It
 * wakes up here, and correctly: a time-sensitive push about a market that closes in 22
 * minutes has no business queueing behind an Overpass ingest.
 *
 * THIS JOB DECIDES NOTHING. The policy already ran, and its answer is a row. If somebody
 * ever adds an `if` to this file, the deterministic-policy guarantee (non-negotiable #4)
 * has quietly stopped being true.
 */
final class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue(QueueLane::Realtime->value);
        $this->onConnection(QueueLane::Realtime->connection());
    }

    public function handle(PushSender $sender): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null || ! $notification->allowed || $notification->wasSent()) {
            return;   // idempotent: a retry must not push the same thing twice
        }

        $device = Device::query()
            ->where('user_id', $notification->user_id)
            ->whereNull('revoked_at')
            ->latest('last_seen_at')
            ->first();

        if ($device === null) {
            // Nobody to tell. Recorded, not thrown: "we approved it and had no live handset"
            // is a fact worth keeping, and it is not an error anybody can fix by retrying.
            $notification->forceFill(['delivery_error' => 'no_live_device'])->save();

            return;
        }

        $opportunityId = $notification->opportunity_id;

        $messageId = $sender->send(new PushMessage(
            pushToken: $device->push_token,
            platform: $device->platform,
            title: (string) ($notification->trace['title'] ?? 'Something near you'),
            body: (string) ($notification->trace['body'] ?? ''),
            // Straight into the DETAIL screen. A push that lands you on a list has wasted the
            // interruption it just spent.
            deepLink: $opportunityId === null ? '/' : "/opportunities/{$opportunityId}",
            notificationId: $notification->id,
        ));

        $notification->forceFill($messageId === null
            ? ['delivery_error' => 'send_failed', 'device_id' => $device->id]
            : ['sent_at' => CarbonImmutable::now(), 'device_id' => $device->id, 'delivery_error' => null],
        )->save();
    }
}
