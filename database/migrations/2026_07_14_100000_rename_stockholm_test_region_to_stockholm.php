<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `stockholm-test` → `stockholm`.
 *
 * The region was named while it was a pipeline test over a 93 km² slice of the
 * inner city. It is now the home region, covering the whole municipality — and
 * carrying a published pack with 31 approved curated items that a founder spent
 * real hours reviewing.
 *
 * `region_slug` is a LABEL. Nothing serves from it: CuratedScout reads approved
 * items by `place_id` and H3 tile (Curation\Services\ApprovedCuratedItems), so no
 * curated item stops being served for even a moment while this runs. That is what
 * makes renaming safe rather than reckless.
 *
 * Reversible, and deliberately narrow: it only touches rows that carry the old
 * slug, so re-running it or rolling it back cannot catch anything else.
 */
return new class extends Migration
{
    private const OLD = 'stockholm-test';

    private const NEW = 'stockholm';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('packs')->where('region_slug', self::OLD)->update(['region_slug' => self::NEW]);
            DB::table('curated_items')->where('region_slug', self::OLD)->update(['region_slug' => self::NEW]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('packs')->where('region_slug', self::NEW)->update(['region_slug' => self::OLD]);
            DB::table('curated_items')->where('region_slug', self::NEW)->update(['region_slug' => self::OLD]);
        });
    }
};
