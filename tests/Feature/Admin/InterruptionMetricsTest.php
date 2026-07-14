<?php

declare(strict_types=1);

use App\Admin\Queries\InterruptionMetrics;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E44 — the interruption-quality read
|--------------------------------------------------------------------------
|
| The notifications table records denials as well as sends (E30) precisely so this is
| answerable — the interesting half of a notification policy is what it did NOT send. These
| tests feed it synthetic decisions and check the rates come out right, and that the read
| refuses to render a verdict on too little data.
|
*/

function notification(int $userId, array $attrs): void
{
    DB::table('notifications')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'allowed' => false,
        'notification_policy_version' => 'v1',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('measures acceptance, annoyance and silence from the ledger', function () {
    $user = User::factory()->create();

    // Ten considered: 4 allowed+sent, 6 denied (silence). Of the 4 sent: 3 opened, 1 swiped.
    notification($user->id, ['allowed' => true, 'sent_at' => now(), 'opened_at' => now()]);
    notification($user->id, ['allowed' => true, 'sent_at' => now(), 'opened_at' => now()]);
    notification($user->id, ['allowed' => true, 'sent_at' => now(), 'opened_at' => now()]);
    notification($user->id, ['allowed' => true, 'sent_at' => now(), 'dismissed_at' => now()]);

    foreach (['quiet_hours', 'quiet_hours', 'driving', 'daily_budget', 'cooldown', 'low_confidence'] as $gate) {
        notification($user->id, ['denied_by' => $gate]);
    }

    $m = app(InterruptionMetrics::class)('7d');

    expect($m->considered)->toBe(10)
        ->and($m->allowed)->toBe(4)
        ->and($m->sent)->toBe(4)
        // 3 opened of 4 sent.
        ->and($m->acceptanceRate)->toBe(0.75)
        // 1 swiped of 4 sent.
        ->and($m->annoyanceRate)->toBe(0.25)
        // 6 held back of 10 considered — restraint, and it is a good number.
        ->and($m->silenceRate)->toBe(0.6);
});

it('breaks the silence down by the gate that caused it', function () {
    $user = User::factory()->create();

    notification($user->id, ['denied_by' => 'quiet_hours']);
    notification($user->id, ['denied_by' => 'quiet_hours']);
    notification($user->id, ['denied_by' => 'driving']);

    $m = app(InterruptionMetrics::class)('7d');

    expect($m->denialsByGate['quiet_hours'])->toBe(2)
        ->and($m->denialsByGate['driving'])->toBe(1);
});

it('counts Trip Mode abandonment — turned on, then off mid-trip', function () {
    $user = User::factory()->create();

    // One person kept it on. (One active trip per user — the partial unique index — so the
    // abandoner is a second traveller.)
    Trip::factory()->create(['user_id' => $user->id, 'trip_mode_started_at' => now()->subHours(3)]);

    // Another started, then turned it OFF while the trip was still going. The sharpest
    // annoyance signal there is — an action, not a survey.
    Trip::factory()->create([
        'user_id' => User::factory()->create()->id,
        'trip_mode_started_at' => now()->subHours(3),
        'trip_mode_ended_at' => now()->subHour(),
        'ended_at' => null,
    ]);

    $m = app(InterruptionMetrics::class)('7d');

    expect($m->tripModeStarted)->toBe(2)
        ->and($m->tripModeAbandoned)->toBe(1)
        ->and($m->abandonmentRate)->toBe(0.5);
});

it('reports zero rates without dividing by zero when nothing happened', function () {
    $m = app(InterruptionMetrics::class)('7d');

    expect($m->acceptanceRate)->toBe(0.0)
        ->and($m->silenceRate)->toBe(0.0)
        ->and($m->abandonmentRate)->toBe(0.0)
        ->and($m->denialsByGate)->toBe([]);
});
