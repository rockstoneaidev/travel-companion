<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scout observability (E5, conventions/08 §traces): every warm run records
 * what it covered and what the cache did. Hit rate per source is a product
 * metric, not an ops metric (conventions/12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scout_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('scout', 32)->index();
            $table->string('scout_version', 32);
            $table->unsignedInteger('tiles_requested');
            $table->unsignedInteger('tiles_hit');
            $table->unsignedInteger('tiles_filled');
            $table->unsignedInteger('candidates');
            $table->unsignedInteger('duration_ms');
            $table->string('trigger', 32)->default('session');   // session | command | warmup

            $table->timestampsTz();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scout_runs');
    }
};
