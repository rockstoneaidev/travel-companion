<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source_ref` — where a time-bound opportunity came from (E39).
 *
 * An evergreen opportunity is keyed by (place, kind): a place has one "it's here, go see
 * it". An ephemeral one is not — a single place can carry two live closures from two
 * articles, and the same closure re-read on the next poll must refresh its row rather than
 * spawn a twin. The dedupe key is the source URL, so it lives on the row.
 *
 * Nullable: evergreen opportunities have no source article, and never will.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->text('source_ref')->nullable()->after('prompt_version');
            $table->index(['place_id', 'kind', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex(['place_id', 'kind', 'source_ref']);
            $table->dropColumn('source_ref');
        });
    }
};
