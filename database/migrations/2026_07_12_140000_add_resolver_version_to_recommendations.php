<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §15.1: every recommendation records ALL the versions that produced it.
 * scoring/taxonomy/prompt were there from E1; the resolver's version joins
 * them (append-only, nullable for pre-existing rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('resolver_version', 32)->nullable()->after('taxonomy_version');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn('resolver_version');
        });
    }
};
