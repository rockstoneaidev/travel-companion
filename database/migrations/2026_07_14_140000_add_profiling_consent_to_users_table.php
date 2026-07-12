<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit consent for the inferred taste profile (GDPR Art. 9(2)(a), DPIA §3.2).
 *
 * We never ASK for special-category data. But the taxonomy has a `religious_sacred`
 * domain and a `spiritual` facet, and the profile learns a weight for them — so a
 * user who keeps visiting churches accumulates a vector that is, in substance, an
 * inferred statement about their religious belief. The CJEU has held that data from
 * which special-category data can be INDIRECTLY DEDUCED falls under Art. 9
 * (C-184/20, OT v Vyriausybinė). The same mechanism reaches health.
 *
 * Art. 6 consent is not enough for that; Art. 9(2)(a) requires EXPLICIT consent —
 * a separate, affirmative, informed act, not a button someone pressed on their way
 * to the next screen.
 *
 * Nullable, and null means NO. Consent that has to be given is consent; consent
 * that has to be revoked is not, and a default of "granted" for existing rows would
 * be exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('profiling_consent_at')->nullable();

            // Versioned, because the thing consented TO can change. If we widen what
            // the profile infers, the old consent no longer covers it and we have to
            // ask again — which is only possible if we recorded what was agreed.
            $table->string('profiling_consent_version', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['profiling_consent_at', 'profiling_consent_version']);
        });
    }
};
