<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-progress-photo-album Phase 1 — progress_snapshots metadata table.
 *
 * Stores ONLY metadata (weight + mood + notes + taken_at + opaque
 * device-local photo_ref). The actual photo bytes never leave the user's
 * device — that's the privacy spine of this feature (SPEC §2.3 §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTimeTz('taken_at');
            $table->unsignedSmallInteger('weight_g')->nullable()->comment('weight × 1000 to avoid float drift; nullable so users can log without weight');
            $table->string('mood', 16)->nullable()->comment('emoji shortcode or short string');
            $table->string('notes', 500)->nullable();
            $table->string('photo_ref', 64)->nullable()->comment('device-local photo identifier; opaque to server');
            $table->timestamps();

            $table->index(['user_id', 'taken_at'], 'progress_snapshots_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_snapshots');
    }
};
