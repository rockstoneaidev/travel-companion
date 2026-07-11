<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVIDENCE-STORE ZONE: the per-opportunity evidence bundle rows (PRD §14.2,
 * conventions/10). Every user-facing claim traces back to rows here — source
 * transparency is a product requirement (PRD §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('source', 32);               // adapter key
            $table->string('license', 32);              // SourceLicense — CC BY-SA excerpts live here, never in the core
            $table->string('credibility_tier', 16);     // CredibilityTier
            $table->text('url')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('attribution')->nullable();
            $table->timestampTz('retrieved_at');

            $table->timestampsTz();

            $table->index('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_evidence');
    }
};
