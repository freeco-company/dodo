<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_plays', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('card_id');
            $table->string('card_type')->comment('knowledge / scenario / event');
            $table->string('rarity')->nullable();
            $table->integer('choice_idx')->nullable();
            $table->boolean('correct')->nullable();
            $table->unsignedInteger('xp_gained')->default(0);
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_plays');
    }
};
