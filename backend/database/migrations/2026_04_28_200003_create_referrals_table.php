<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('reward_kind', 32)->default('trial_extension_7d');
            $table->timestamp('created_at')->useCurrent();

            $table->index('referrer_id');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
