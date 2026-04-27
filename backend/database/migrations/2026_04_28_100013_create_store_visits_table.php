<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_visits', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('store_key');
            $table->unsignedInteger('visit_count')->default(0);
            $table->unsignedInteger('intent_count')->default(0);
            $table->timestamp('first_visit_at')->nullable();
            $table->timestamp('last_visit_at')->nullable();

            $table->unique(['user_id', 'store_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_visits');
    }
};
