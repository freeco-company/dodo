<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->comment('ios | android | web');
            $table->string('token', 512);
            $table->json('device_info')->nullable();
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('disabled_at')->nullable();

            // platform + prefix of token enforces uniqueness; full token may be > 191 bytes
            $table->unique(['platform', 'token'], 'push_tokens_platform_token_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
