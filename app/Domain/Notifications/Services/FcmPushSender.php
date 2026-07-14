<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Contracts\PushSender;
use App\Domain\Notifications\Data\PushMessage;
use App\Domain\Sources\Services\CircuitBreaker;
use Illuminate\Support\Facades\Http;

/**
 * FCM v1 (PRD Appendix A: "FCM cross-platform; APNs direct if needed later").
 *
 * Behind the same circuit breaker as every other outbound dependency: a push provider
 * having a bad afternoon must degrade to "we did not interrupt you", which is the failure
 * mode this product can live with most comfortably of all.
 *
 * ROPA: FCM is a PROCESSOR — it receives a push token and the message body, which for us
 * means a place name and a time. That is personal data leaving to Google, and it needs a
 * DPA before the first real send (PROCESSORS.md; ROPA §6). See the warning in send().
 */
final class FcmPushSender implements PushSender
{
    private const SOURCE = 'fcm';

    private const TIMEOUT_SECONDS = 6;

    public function __construct(
        private readonly CircuitBreaker $breaker,
    ) {}

    public function send(PushMessage $message): ?string
    {
        $project = (string) config('services.fcm.project_id');
        $token = (string) config('services.fcm.access_token');

        if ($project === '' || $token === '') {
            return null;   // not configured is not an error; it is "we cannot reach anybody"
        }

        return $this->breaker->call(
            self::SOURCE,
            fn (): ?string => Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $message->pushToken,
                        'notification' => [
                            'title' => $message->title,
                            'body' => $message->body,
                        ],
                        'data' => [
                            // The deep link goes into DATA, not into the notification body:
                            // the client must land on the detail screen, and a push that
                            // drops you on a list has wasted the interruption it just spent.
                            'deep_link' => $message->deepLink,
                            'notification_id' => $message->notificationId,
                        ],
                    ],
                ])
                ->throw()
                ->json('name'),
            fallback: null,
        );
    }
}
