<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-place imagery (SCREENS build note 6): Wikimedia Commons files found via
 * Wikidata P18. License varies PER FILE (DATA-SOURCES §2) — every row carries
 * its own attribution and license, rendered wherever the image is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_images', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('place_id')->constrained('places_core')->cascadeOnDelete();
            $table->string('source', 32)->default('wikimedia_commons');
            $table->string('file_name', 512);
            $table->string('url', 1024);                 // 800px thumb
            $table->string('attribution', 512)->nullable(); // artist, per Commons extmetadata
            $table->string('license', 64)->nullable();      // e.g. CC BY-SA 4.0
            $table->timestampTz('retrieved_at');

            $table->timestampsTz();

            $table->unique(['place_id', 'file_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_images');
    }
};
