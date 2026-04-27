<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_quests', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('quest_key');
            $table->unsignedInteger('target');
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('reward_xp')->default(0);

            $table->unique(['user_id', 'date', 'quest_key']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_quests');
    }
};
