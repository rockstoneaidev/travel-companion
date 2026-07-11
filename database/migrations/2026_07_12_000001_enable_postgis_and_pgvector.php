<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product's two load-bearing Postgres extensions (conventions/03,
 * deployment/docker/postgres/). The container init also enables them for the
 * default database; this migration makes any database (CI service, test DB,
 * fresh env) self-contained.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Deliberately not dropped: other schemas/databases on shared clusters
        // may rely on them, and PostGIS drops are destructive to columns.
    }
};
