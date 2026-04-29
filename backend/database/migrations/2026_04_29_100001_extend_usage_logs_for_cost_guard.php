<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->after('tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('latency_ms')->nullable()->after('output_tokens');
            // Index supports monthly-cost queries: WHERE user_id = ? AND date >= ?
            $table->index(['pandora_user_uuid', 'date'], 'usage_logs_uuid_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropIndex('usage_logs_uuid_date_idx');
            $table->dropColumn(['input_tokens', 'output_tokens', 'latency_ms']);
        });
    }
};
