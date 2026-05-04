<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-daily-login-streak — per-App 每日連續登入 streak.
 *
 * 與 users.current_streak / longest_streak 的差異：那是 legacy 朵朵核心
 * (記餐 / 簽到) streak，本表專收「每日打開 App 的登入 streak」，這樣未來
 * 把它抽成集團 master streak 時不影響既有遊戲邏輯。
 *
 * 一 user 一 row（unique user_id）；middleware 每 request 跑一次：
 *   - last_login_date == today  → no-op
 *   - last_login_date == yesterday → +1
 *   - else → reset to 1
 *
 * 日期一律走 Asia/Taipei。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_daily_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_login_date')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_streaks');
    }
};
