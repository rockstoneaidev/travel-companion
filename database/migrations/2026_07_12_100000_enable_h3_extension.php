<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * h3-pg (PGDG postgresql-18-h3) — the canonical res-8 tile grid (conventions/12).
 * The custom Postgres image installs the package; this makes any database
 * self-contained, same as postgis/vector in the image init SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3');
        DB::statement('CREATE EXTENSION IF NOT EXISTS h3_postgis CASCADE');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS h3_postgis');
        DB::statement('DROP EXTENSION IF EXISTS h3');
    }
};
