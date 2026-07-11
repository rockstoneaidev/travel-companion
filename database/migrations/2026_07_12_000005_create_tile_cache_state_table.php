<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-tile bookkeeping (PRD §14.2, conventions/12): when was a tile last
 * scouted per source, so "is this tile cold?" is answerable without probing
 * cache keys. The cache itself lives in Redis; this is the durable record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tile_cache_state', function (Blueprint $table) {
            $table->id();

            $table->string('h3_index', 20);
            $table->string('source', 32);
            $table->string('source_adapter_version', 32);
            $table->timestampTz('last_scouted_at')->nullable();
            $table->unsignedInteger('items_count')->default(0);

            $table->timestampsTz();

            $table->unique(['h3_index', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tile_cache_state');
    }
};
