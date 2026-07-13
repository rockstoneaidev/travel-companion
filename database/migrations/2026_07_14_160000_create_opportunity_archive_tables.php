<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The temporal archive (VISION.md §2): what was going on in an area, kept after
 * the moment has passed. `opportunities` stay ephemeral and TTL'd exactly as
 * decided (conventions/03) — this is where the reaper moves the license-storable
 * subset of EXPIRED time-bound opportunities (event/ephemeral/seasonal) instead
 * of deleting history that can never be recreated. Evergreen materializations
 * are reaped without archiving: the place itself is permanent in places_core,
 * and a daily "this park still exists" row is not history.
 *
 * NOTHING READS THESE TABLES YET, by design. They are an append-only record for
 * a future content/SEO surface (VISION.md §3); serving decisions must never
 * consult them.
 *
 * Zones (conventions/03): `archived_opportunities` is PROPRIETARY SHELL (our
 * own titles/summaries); `archived_opportunity_evidence` is EVIDENCE-STORE
 * (per-row license + attribution). Only evidence from sources whose descriptor
 * says `archivable` lands here — indefinite retention is a narrower right than
 * TTL'd storage, and the reaper checks it per row.
 *
 * Deliberate denormalisation: `place_id` carries NO foreign key and the place
 * name is snapshotted. The archive must survive resolver merges and place
 * deletions — it records what was true then, not what the world model says now.
 * No `friction` either: walk-minutes from where a user once stood is momentary
 * by definition, and parts of it may be edge-sourced (Google Routes) and thus
 * not ours to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_opportunities', function (Blueprint $table) {
            // The original opportunity id — the natural key that makes the
            // reaper idempotent (ON CONFLICT DO NOTHING).
            $table->uuid('id')->primary();

            $table->uuid('place_id')->index();          // no FK, on purpose (see above)
            $table->string('place_name')->nullable();   // snapshot at archive time

            $table->string('kind', 24)->index();        // OpportunityKind — never Evergreen
            $table->string('status', 24);               // how far the pipeline took it
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->string('prompt_version', 32)->nullable();

            $table->timestampTz('window_starts_at')->nullable();
            $table->timestampTz('window_ends_at')->nullable();

            $table->string('h3_index', 20);
            $table->timestampTz('first_seen_at');       // the live row's created_at
            $table->timestampTz('expired_at');          // the live row's expires_at
            $table->timestampTz('archived_at');

            // The query this table exists for: what was going on HERE, THEN.
            $table->index(['h3_index', 'expired_at']);
        });

        Schema::create('archived_opportunity_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('archived_opportunity_id')
                ->constrained('archived_opportunities')
                ->cascadeOnDelete();

            $table->string('source', 32);               // adapter key — archivable per its descriptor
            $table->string('license', 32);              // SourceLicense, per row, as in opportunity_evidence
            $table->string('credibility_tier', 16);
            $table->text('url')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('attribution')->nullable();
            $table->timestampTz('retrieved_at');

            $table->index('archived_opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_opportunity_evidence');
        Schema::dropIfExists('archived_opportunities');
    }
};
