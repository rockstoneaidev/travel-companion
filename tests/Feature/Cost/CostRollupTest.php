<?php

declare(strict_types=1);

use App\Cost\Services\CostRollup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E25 — the rollup (docs/COST.md §7.1, §2.2)
|--------------------------------------------------------------------------
|
| The ledger records CAUSAL truth: whoever lit the fuse pays. That is the only
| thing knowable at write time, and it is a terrible bill — the first traveller
| into a cold region pays for a tile the next forty read for free.
|
| So the rollup derives what the ledger cannot know until the day is over. The
| headline test below asserts that the causal and amortised views DISAGREE, in
| the specific direction the design predicts. If they ever agree, the allocation
| has silently become a no-op and the whole table is decoration.
|
*/

function costEvent(array $attributes = []): void
{
    DB::table('cost_events')->insert([...[
        'occurred_at' => now()->subHours(2),
        'actor_kind' => 'user',
        'category' => 'llm',
        'vendor' => 'gemini',
        'resource' => 'gemini-3.1-flash-lite',
        'billed_usd_micros' => 0,
        'would_have_billed_usd_micros' => 0,
        'cached' => false,
        'price_version' => '2026-07',
        'created_at' => now(),
    ], ...$attributes]);
}

it('moves cost from the payer onto everyone who used what they paid for', function () {
    // The scenario the whole design exists for. Two travellers, one place:
    //
    //   · Ana walks past the church first. Cache is cold, the model runs, she pays 600µ.
    //   · Bo walks past an hour later. Cache is warm, he pays nothing.
    //
    // CAUSALLY, Ana costs 600 and Bo costs 0 — which is true, and which would make Ana
    // look like a whale and Bo like a free customer. Neither is a fact about the users;
    // both are facts about who arrived first.
    $ana = User::factory()->create();
    $bo = User::factory()->create();

    costEvent(['user_id' => $ana->id, 'billed_usd_micros' => 600, 'would_have_billed_usd_micros' => 600]);
    costEvent(['user_id' => $bo->id, 'billed_usd_micros' => 0, 'would_have_billed_usd_micros' => 600, 'cached' => true]);

    app(CostRollup::class)(now()->startOfDay());

    $anaRow = DB::table('cost_daily')->where('user_id', $ana->id)->first();
    $boRow = DB::table('cost_daily')->where('user_id', $bo->id)->first();

    // The causal column is untouched, and still says what actually happened.
    expect((int) $anaRow->billed_usd_micros)->toBe(600)
        ->and((int) $boRow->billed_usd_micros)->toBe(0);

    // The amortised column says what each of them is WORTH: they consumed the same line,
    // so they carry the same 300µ. This disagreement is the system working.
    expect((int) $anaRow->amortized_usd_micros)->toBe(300)
        ->and((int) $boRow->amortized_usd_micros)->toBe(300);

    // And the two views still add up to the same money. Amortisation MOVES cost between
    // people; it must never create or destroy any.
    $causal = (int) DB::table('cost_daily')->sum('billed_usd_micros');
    $amortized = (int) DB::table('cost_daily')->sum('amortized_usd_micros');

    expect($amortized)->toBe($causal);
});

it('spreads a region pack over the region it was built for, not the admin who clicked build', function () {
    // Attribute a pack build to whoever triggered it and one operator "costs" more than
    // every real user combined — and every per-user number becomes garbage (COST.md §2.1).
    $ana = User::factory()->create();
    $bo = User::factory()->create();

    // The pack: system spend, on nobody's behalf, keyed to a region.
    costEvent([
        'actor_kind' => 'system', 'user_id' => null, 'region_key' => 'stockholm',
        'model' => 'gemini-3.5-flash', 'billed_usd_micros' => 8_000,
        'would_have_billed_usd_micros' => 8_000,
    ]);

    // Two users active in that region.
    foreach ([$ana, $bo] as $user) {
        costEvent([
            'user_id' => $user->id, 'region_key' => 'stockholm',
            'billed_usd_micros' => 600, 'would_have_billed_usd_micros' => 600,
        ]);
    }

    app(CostRollup::class)(now()->startOfDay());

    // The capex is a SEPARATE column, not folded into the user's own spend: "what this
    // user cost me" and "what building the region cost me" are different questions, and a
    // single number that averaged them could answer neither.
    $rows = DB::table('cost_daily')->whereIn('user_id', [$ana->id, $bo->id])->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect((int) $row->capex_share_usd_micros)->toBe(4_000)   // 8,000 / 2 users
            ->and((int) $row->billed_usd_micros)->toBe(600);      // their own spend, untouched
    }

    // The system row is still there, still saying the truth about where the money went.
    $system = DB::table('cost_daily')->where('actor_kind', 'system')->first();

    expect((int) $system->billed_usd_micros)->toBe(8_000)
        ->and($system->user_id)->toBeNull();
});

it('keeps an emulated founder session out of the product numbers', function () {
    // ADMIN §2.4. Most of today's traffic IS the founder testing from an emulated
    // position, and if that lands in cost-per-trip-hour then the one metric PRD §14.3
    // actually asks for is measuring the developer, not the product.
    $user = User::factory()->create();

    costEvent(['actor_kind' => 'admin_emulated', 'user_id' => $user->id, 'billed_usd_micros' => 5_000, 'would_have_billed_usd_micros' => 5_000]);
    costEvent(['user_id' => $user->id, 'billed_usd_micros' => 600, 'would_have_billed_usd_micros' => 600]);

    app(CostRollup::class)(now()->startOfDay());

    // Both rows exist — the wallet still sees the €5,000µ, because it was really spent...
    expect((int) DB::table('cost_daily')->sum('billed_usd_micros'))->toBe(5_600);

    // ...but only the real-user row is amortised, so nothing downstream that filters on
    // actor_kind = user can accidentally count the founder's afternoon.
    $emulated = DB::table('cost_daily')->where('actor_kind', 'admin_emulated')->first();

    expect((int) $emulated->amortized_usd_micros)->toBe(5_000)   // untouched by amortisation
        ->and($emulated->actor_kind)->toBe('admin_emulated');
});

it('can be re-run for a day without doubling it', function () {
    // Re-rolling is not an edge case — it is what you do the moment a price sheet is
    // corrected, which is the moment the numbers most need to move.
    $user = User::factory()->create();

    costEvent(['user_id' => $user->id, 'billed_usd_micros' => 600, 'would_have_billed_usd_micros' => 600]);

    app(CostRollup::class)(now()->startOfDay());
    app(CostRollup::class)(now()->startOfDay());
    app(CostRollup::class)(now()->startOfDay());

    expect(DB::table('cost_daily')->count())->toBe(1)
        ->and((int) DB::table('cost_daily')->sum('billed_usd_micros'))->toBe(600);
});
