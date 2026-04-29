<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for admin-driven lifecycle overrides via the Filament Leads inbox
 * (or any other admin tooling that calls LifecycleAdminClient).
 *
 * Independent of py-service's transition log — this table records the *intent*
 * and *who pressed the button*, even when the downstream py-service call fails
 * (success/failure + error stored). py-service has its own transition history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_override_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('pandora_user_uuid', 36)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason');
            $table->string('actor_email', 191);
            $table->boolean('succeeded');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_override_logs');
    }
};
