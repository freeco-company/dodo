<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key');
            $table->string('achievement_name');
            $table->timestamp('unlocked_at')->useCurrent();

            $table->unique(['user_id', 'achievement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
