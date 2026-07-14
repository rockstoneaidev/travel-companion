<?php

declare(strict_types=1);

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Notifications\Actions\ConsiderNotification;
use App\Domain\Notifications\Contracts\PushSender;
use App\Domain\Notifications\Data\PushMessage;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Trips\Enums\DevicePlatform;
use App\Domain\Trips\Models\Device;
use App\Jobs\Delivery\SendPushNotificationJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// The realtime lane is Redis, which the host PHP cannot speak (CLAUDE.md). We call the job's
// handle() directly anyway — what is under test is delivery, not the queue.
beforeEach(fn () => Queue::fake());

/*
|--------------------------------------------------------------------------
| E31 — the delivery rails
|--------------------------------------------------------------------------
|
| A push is I/O to somebody else's infrastructure. The job that performs it DECIDES
| NOTHING: the policy already ran and its answer is a row. If an `if` ever appears in
| SendPushNotificationJob, the deterministic-policy guarantee (non-negotiable #4) has
| quietly stopped being true.
|
*/

/** A sender that records what it was asked to send, and pretends it worked. */
function capturingSender(): object
{
    return new class implements PushSender
    {
        /** @var list<PushMessage> */
        public array $sent = [];

        public function send(PushMessage $message): ?string
        {
            $this->sent[] = $message;

            return 'msg-1';
        }
    };
}

function liveDevice(User $user): Device
{
    return Device::query()->create([
        'user_id' => $user->id,
        'platform' => DevicePlatform::Ios,
        'push_token' => 'tok-'.$user->id.'-abcdefgh',
        'last_seen_at' => CarbonImmutable::now(),
    ]);
}

it('carries an approved notification to a handset, deep-linked into the detail screen', function () {
    $sender = capturingSender();
    app()->instance(PushSender::class, $sender);

    $user = User::factory()->create();
    $trip = tripInMode($user);
    liveDevice($user);

    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());
    expect($notification->allowed)->toBeTrue();

    app(SendPushNotificationJob::class, ['notificationId' => $notification->id])->handle($sender);

    expect($sender->sent)->toHaveCount(1)
        /*
         * Straight into the DETAIL screen, never the feed. A push that says "the market
         * closes in 22 minutes" and lands you on a list has wasted the interruption it just
         * spent (PRD §12.3).
         */
        ->and($sender->sent[0]->deepLink)->toBe("/opportunities/{$notification->opportunity_id}")
        ->and($notification->fresh()->wasSent())->toBeTrue();
});

it('does not push the same thing twice when a job is retried', function () {
    $sender = capturingSender();
    app()->instance(PushSender::class, $sender);

    $user = User::factory()->create();
    $trip = tripInMode($user);
    liveDevice($user);

    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());

    // A queue retries. It is allowed to. What it is not allowed to do is interrupt somebody
    // twice for one decision — the whole budget is meaningless if delivery can double it.
    $job = app(SendPushNotificationJob::class, ['notificationId' => $notification->id]);
    $job->handle($sender);
    $job->handle($sender);

    expect($sender->sent)->toHaveCount(1);
});

it('records that it had nobody to tell, rather than throwing', function () {
    $sender = capturingSender();
    app()->instance(PushSender::class, $sender);

    $user = User::factory()->create();   // no device
    $trip = tripInMode($user);

    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());

    app(SendPushNotificationJob::class, ['notificationId' => $notification->id])->handle($sender);

    // "We approved it and had no live handset" is a fact worth keeping, and it is not an
    // error anybody can fix by retrying.
    expect($sender->sent)->toBeEmpty()
        ->and($notification->fresh()->delivery_error)->toBe('no_live_device')
        ->and($notification->fresh()->wasSent())->toBeFalse();
});

it('never sends to a phone that was silenced', function () {
    $sender = capturingSender();
    app()->instance(PushSender::class, $sender);

    $user = User::factory()->create();
    $trip = tripInMode($user);

    $device = liveDevice($user);
    $device->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

    $notification = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());
    app(SendPushNotificationJob::class, ['notificationId' => $notification->id])->handle($sender);

    expect($sender->sent)->toBeEmpty()
        ->and($notification->fresh()->delivery_error)->toBe('no_live_device');
});

it('turns an opened push into a signal, and a swiped one into a different signal', function () {
    $sender = capturingSender();
    app()->instance(PushSender::class, $sender);

    Sanctum::actingAs($user = profilingConsent(User::factory()->create()));
    $trip = tripInMode($user);
    liveDevice($user);

    $opened = app(ConsiderNotification::class)($user->id, $trip->id, candidate($user), noon());

    $this->postJson("/api/v1/notifications/{$opened->id}/opened")->assertNoContent();

    expect($opened->fresh()->opened_at)->not->toBeNull();

    // Opened ⇒ `accepted`. A weak positive: they LOOKED. Whether they went is answered later,
    // by the visit prompt, which is the golden label (§7.1).
    expect(DB::table('recommendation_feedback')
        ->where('recommendation_id', $opened->recommendation_id)
        ->where('event', FeedbackEvent::Accepted->value)
        ->count())->toBe(1);

    /*
     * And a swipe ⇒ `ignored`, NOT `dismissed`. This is the whole subtlety of the receipt,
     * and getting it backwards would poison the taste profile.
     *
     * `dismissed` is "not my thing" — the strongest negative the learner has (η .25).
     * Swiping away a push says almost nothing about the PLACE: it says the MOMENT was wrong.
     * They were busy, driving, mid-conversation. Punishing a museum because somebody was in
     * a meeting is how a companion slowly learns to recommend nothing at all.
     */
    $swiped = Notification::query()->create([
        'user_id' => $user->id,
        'trip_id' => $trip->id,
        'recommendation_id' => candidate($user)->recommendationId,
        'allowed' => true,
        'notification_policy_version' => 'v1',
    ]);

    $before = UserTasteProfile::for((int) $user->id)->facet_weights;

    $this->postJson("/api/v1/notifications/{$swiped->id}/dismissed")->assertNoContent();

    expect(DB::table('recommendation_feedback')
        ->where('recommendation_id', $swiped->recommendation_id)
        ->where('event', FeedbackEvent::Ignored->value)
        ->count())->toBe(1)
        ->and(DB::table('recommendation_feedback')
            ->where('recommendation_id', $swiped->recommendation_id)
            ->where('event', FeedbackEvent::Dismissed->value)
            ->count())->toBe(0);

    // The place was not punished for the moment.
    expect(UserTasteProfile::for((int) $user->id)->fresh()->facet_weights)->toEqual($before);
});

it('will not let one traveller read another traveller’s receipt', function () {
    $other = User::factory()->create();
    $trip = tripInMode($other);

    $theirs = app(ConsiderNotification::class)($other->id, $trip->id, candidate($other), noon());

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/notifications/{$theirs->id}/opened")->assertForbidden();
    $this->postJson("/api/v1/notifications/{$theirs->id}/dismissed")->assertForbidden();
});
