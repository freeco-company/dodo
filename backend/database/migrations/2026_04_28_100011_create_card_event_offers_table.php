<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_event_offers', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('card_id');
            $table->timestamp('offered_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('pending')
                ->comment('pending | answered | missed');
            $table->foreignId('play_id')->nullable()->constrained('card_plays')->nullOnDelete();
            $table->string('event_group')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_event_offers');
    }
};
