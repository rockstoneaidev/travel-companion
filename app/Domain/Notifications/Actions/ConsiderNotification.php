<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Data\InterruptionContext;
use App\Domain\Notifications\Data\NotificationCandidate;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Queries\InterruptionState;
use App\Domain\Notifications\Services\NotificationPolicy;
use App\Domain\Trips\Contracts\TripLookup;
use App\Jobs\Delivery\SendPushNotificationJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * "Should we interrupt them about this?" — asked, answered, and WRITTEN DOWN (PRD §12.2).
 *
 * Every decision lands in the ledger, allowed or denied, with the gate that stopped it.
 * The denials are not noise: they are the only thing that makes the counterfactual askable
 * ("would policy_v3 have avoided the push policy_v2 sent?"), and they are what the digest
 * valve (§12.4) is built to catch — an opportunity that fails the *push* bar has not failed,
 * it has simply not earned an interruption.
 */
final class ConsiderNotification
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly InterruptionState $state,
        private readonly TripLookup $trips,
    ) {}

    public function __invoke(
        int $userId,
        ?string $tripId,
        NotificationCandidate $candidate,
        ?CarbonImmutable $at = null,
    ): Notification {
        $at ??= CarbonImmutable::now();

        $user = User::query()->findOrFail($userId);
        $trip = $tripId === null ? null : $this->trips->find($tripId);
        $state = $this->state->forUser($userId, $at);

        $context = new InterruptionContext(
            userId: $userId,
            tripId: $tripId,
            inTripMode: $trip?->inTripMode ?? false,
            at: $at,
            // Local hour. Quiet hours are a fact about a person, not about UTC, and a travel
            // product's users cross timezones as a matter of routine.
            localHour: (int) $at->format('G'),
            quietHoursStart: $user->quiet_hours_start,
            quietHoursEnd: $user->quiet_hours_end,
            maxDetourMinutes: $user->max_detour_minutes,
            movementMode: null,
            sentToday: $state['sent_today'],
            lastSentAt: $state['last_sent_at'],
            recentlyRejectedDomains: $state['rejected_domains'],
            sentRecently: $state['sent_recently'],
        );

        $decision = $this->policy->decide($candidate, $context);

        $notification = Notification::query()->create([
            'user_id' => $userId,
            'trip_id' => $tripId,
            'recommendation_id' => $candidate->recommendationId,
            'opportunity_id' => $candidate->opportunityId,
            'allowed' => $decision->allowed,
            'denied_by' => $decision->deniedBy,
            'notification_policy_version' => $decision->policyVersion,
            'priority' => $decision->priority,
            'trace' => $decision->trace,
        ]);

        if ($decision->allowed) {
            try {
                // Queued, never sent inline. A push is I/O to somebody else's infrastructure,
                // and the thing that decided to send it must not be waiting on Google.
                SendPushNotificationJob::dispatch($notification->id)->afterCommit();
            } catch (Throwable $e) {
                /*
                 * A push we could not QUEUE is a push we did not send. It is not a decision
                 * we failed to make.
                 *
                 * The decision is already a row, with its policy version and its trace, and
                 * that row is the thing the replayer and the auditor read. Letting a broken
                 * queue throw here would lose the decision as well as the delivery — and
                 * would make an unreachable Redis look, in the record, exactly like a policy
                 * that chose to stay quiet.
                 */
                $notification->forceFill(['delivery_error' => 'queue_unavailable'])->save();

                report($e);
            }
        }

        return $notification;
    }
}
