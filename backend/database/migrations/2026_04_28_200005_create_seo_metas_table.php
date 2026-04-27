<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metas', function (Blueprint $table) {
            $table->string('path', 191)->primary();
            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('og_image')->nullable();
            $table->string('locale', 16)->default('zh-TW');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_metas');
    }
};
