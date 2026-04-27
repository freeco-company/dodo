<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('fp_ref_code');
            $table->timestamp('deletion_requested_at')->nullable()->after('referral_code');
            $table->timestamp('hard_delete_after')->nullable()->after('deletion_requested_at');
            $table->boolean('push_enabled')->default(true)->after('hard_delete_after');
            $table->timestamp('trial_started_at')->nullable()->after('push_enabled');
            $table->timestamp('trial_expires_at')->nullable()->after('trial_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'referral_code',
                'deletion_requested_at',
                'hard_delete_after',
                'push_enabled',
                'trial_started_at',
                'trial_expires_at',
            ]);
        });
    }
};
