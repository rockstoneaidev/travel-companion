<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The declared home zone, and research consent (PRD §16, E17).
 *
 * The home zone is the whole of Phase 1's sensitive-zone scope: a point and a
 * radius the user declares. Inside it we do not learn, do not serve, and do not
 * store precise coordinates. Automatic home/work inference is Phase 2 — it needs
 * the background location patterns Phase 1 deliberately never collects.
 *
 * Research consent is what exempts an account from trace coarsening, so that
 * full-precision gold traces exist for the replayer (§15.2). It is opt-in, and
 * off by default: nobody is enrolled in a study by omission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->geography('home_zone_center', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedSmallInteger('home_zone_radius_meters')->nullable();

            // Off by default. Consent that has to be given is consent; consent that
            // has to be revoked is not.
            $table->boolean('research_consent')->default(false);
        });

        // The suppression check runs on the serve path for every candidate, so it
        // has to be an index lookup and not a table scan.
        DB::statement('CREATE INDEX users_home_zone_center_gix ON users USING GIST (home_zone_center)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_home_zone_center_gix');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['home_zone_center', 'home_zone_radius_meters', 'research_consent']);
        });
    }
};
