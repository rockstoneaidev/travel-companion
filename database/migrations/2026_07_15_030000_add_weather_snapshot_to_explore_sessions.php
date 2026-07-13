<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weather we actually saw, kept for good.
 *
 * Weather was fetched on every ranked session and then thrown away. The only residue
 * was `weather_c` on the decision trace — a friction COEFFICIENT, not an observation,
 * and ambiguous in the worst possible way: `0` means "it was dry" *and* "we never
 * knew". So the journal could not answer the simplest question anyone asks about a
 * trip they took — what was the weather like?
 *
 * ---------------------------------------------------------------------------
 *  Why this must be a snapshot, and cannot be looked up later
 * ---------------------------------------------------------------------------
 *
 * Open-Meteo's forecast endpoint answers "what is the sky doing now" — it will not
 * tell you what last August in Dijon was like (that is a different, archival API). And
 * the LLM is never a source of facts (non-negotiable #3), so it cannot be asked to
 * remember on our behalf. If we do not write down what we saw at the moment we saw it,
 * the observation is gone permanently. Same asymmetry as the cost ledger: capture is
 * cheap now and impossible retroactively.
 *
 * ---------------------------------------------------------------------------
 *  Why keeping it forever is not a privacy regression
 * ---------------------------------------------------------------------------
 *
 * This is environmental context, not personal data about the user. The row it hangs on
 * already carries the user, the timestamp and (after coarsening) the H3 cell —
 * indefinitely and by design. Adding "19°C, dry" to a row that already says "this
 * person was in this hex at this hour" tells an attacker nothing they could not derive
 * from a public weather archive given the cell and the time.
 *
 * So it is NOT touched by the retention pass, which nulls the precise coordinate at 30
 * days and leaves the cell. The coordinate is the sensitive part. The sky is not.
 * (ROPA §4.1 updated accordingly.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('explore_sessions', function (Blueprint $table): void {
            // The four fields WeatherContext already carries, stored as it traces them
            // (temp_c, precip_mm, code, cloud_pct) — one shape, not two.
            $table->jsonb('weather')->nullable();

            // WHEN the sky looked like this. A session can outlive its weather by hours,
            // and a snapshot with no clock is a claim with no scope.
            $table->timestampTz('weather_observed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('explore_sessions', function (Blueprint $table): void {
            $table->dropColumn(['weather', 'weather_observed_at']);
        });
    }
};
