<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regions the product learned by itself (E48).
 *
 * `IngestRegion` is a hand-reviewed catalogue in CODE, and it says why: "adding a region
 * is a reviewed change, not a config flag". That is right for the launch regions —
 * Stockholm and the France corridor are product decisions, and they should be read in a
 * pull request.
 *
 * It is wrong as the limit of the world. The founder dropped a pin in Skellefteå — a
 * town of 35,000 — and the app had nothing, because Skellefteå is not in a PHP file. A
 * region we derive because a real user actually went somewhere is not a reviewed
 * decision; it is a FACT about demand, and facts belong in a table.
 *
 * So the two live side by side and mean different things: the catalogue is what we chose
 * to know, this is what we were asked to learn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('derived_regions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Same shape as IngestRegion — a derived region IS one, just not in code.
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->decimal('south', 9, 6);
            $table->decimal('west', 9, 6);
            $table->decimal('north', 9, 6);
            $table->decimal('east', 9, 6);

            // Load-bearing, not decorative: the adapters read `name:{locale}` and follow
            // the matching Wikipedia sitelink. Hard-coding Swedish is what the France
            // corridor caught (E13).
            $table->string('locale', 8)->default('en');

            /*
             * WHO asked, and WHEN. A derived region is a demand signal — VISION §1's
             * "which regions have the most booked user-days" starts here — and a region
             * with no requester is a region nobody can explain.
             */
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');

            $table->timestampsTz();

            // "Is this point already covered?" — the dedupe question, asked on every
            // session start in an unknown area.
            $table->index(['south', 'north']);
            $table->index(['west', 'east']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('derived_regions');
    }
};
