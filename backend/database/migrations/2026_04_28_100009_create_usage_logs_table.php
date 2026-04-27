<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind')->comment('scan | chat');
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
