<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram index on places_core.name.
 *
 * Two callers do fuzzy name matching and both were sequential-scanning:
 * the manual-origin place search (SCREENS S2) and entity resolution's blocked
 * fuzzy matching (ENTITY-RESOLUTION §3). pg_trgm is already enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX places_core_name_trgm ON places_core USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS places_core_name_trgm');
    }
};
