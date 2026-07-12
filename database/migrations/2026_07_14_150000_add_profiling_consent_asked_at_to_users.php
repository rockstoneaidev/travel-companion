<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When we ASKED — which is a different fact from what they answered.
 *
 * Without it there is no way to tell "has not consented" from "has not been asked",
 * and the only way to get consent from an existing user would be to keep sending
 * them to the consent screen until they agreed. That is nagging, and nagging
 * invalidates the consent it extracts: it must be FREELY GIVEN (Art. 4(11)), and a
 * choice you are shown on every page load until you pick the right answer is not a
 * free one.
 *
 * So: ask once, record that we asked, respect the answer. Someone who declined is
 * never asked again — they can turn it on themselves in Settings → Privacy, on their
 * own initiative, which is what a free choice looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('profiling_consent_asked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profiling_consent_asked_at');
        });
    }
};
