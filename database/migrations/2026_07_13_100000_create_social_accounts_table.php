<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with Google (E22).
 *
 * A table rather than a `google_id` column on `users`: Phase 2 ships a native
 * iOS client, and the App Store requires Sign in with Apple wherever a
 * third-party login is offered. A second provider must be a row, not a schema
 * change.
 *
 * `users.password` becomes nullable because "signed up with Google, never set a
 * password" is a real, permanent account state — not a placeholder to be filled
 * with an unguessable random hash. A null here is the signal the settings screen
 * reads to offer "set a password" instead of "update password"; a random hash
 * would make a passwordless account indistinguishable from a password one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 32);              // SocialProvider — google
            $table->string('provider_user_id', 191);     // Google's stable `sub`; never the email
            $table->string('email')->nullable();         // what the provider asserted, for support/debugging
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();

            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            // One provider identity maps to exactly one account: the uniqueness
            // that makes "find by provider id" a safe login, and blocks a second
            // user from claiming the same Google account.
            $table->unique(['provider', 'provider_user_id']);

            // A user links a given provider at most once.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        // Rows created by Google sign-in have no password; they must go before
        // the column can be NOT NULL again, or the change fails.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
