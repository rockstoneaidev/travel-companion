<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get the application TestCase and a transactional database.
| Unit tests get neither — if a "unit" test needs the database, it belongs in
| Feature. See docs/conventions/11-testing.md.
|
| The database is real PostgreSQL + PostGIS + pgvector, not SQLite: the geo and
| vector columns this product is built on cannot exist on SQLite.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific expectations. Keep them few and meaningful.
|
*/

expect()->extend('toBeIso8601', function () {
    expect($this->value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Geo fixtures are real coordinates in the launch region, never (0, 0) — null
| island passes tests and hides bugs (conventions/11).
|
*/

/** Liljeholmen, Stockholm — the test region (PRD §8.0). @return array{lat: float, lng: float} */
function stockholmOrigin(): array
{
    return ['lat' => 59.3100, 'lng' => 18.0200];
}

/**
 * A user who has explicitly consented to being profiled (Art. 9(2)(a), DPIA §3.2).
 *
 * Learning is gated on this now, at the learner — the one place a facet weight can
 * actually move. So any test that asserts the taste profile CHANGED has to consent
 * first, exactly as a real user does.
 *
 * That the gate broke a handful of existing tests is the gate working: every one of
 * them was, until today, learning a taste vector from someone who had never agreed
 * to have one. ProfilingConsentTest is the test for what happens when they refuse.
 */
function profilingConsent(User $user): User
{
    User::query()->whereKey($user->id)->update([
        'profiling_consent_at' => now(),
        'profiling_consent_version' => config('privacy.profiling_consent_version'),
    ]);

    return $user;
}
