<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trial-fraud blacklist (TrialService TODO unblocked by AppleSignIn / LineSignIn).
 *
 * When a user account is hard-deleted (AccountDeletionService::purge), we copy
 * their apple_id / line_id here so a re-registration via the same OAuth
 * provider id cannot re-mint a 7-day trial. We do NOT store email — email is
 * easy to swap; the OAuth `sub` is the stable per-user identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_trial_blacklist', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['apple', 'line']);
            // Apple `sub` and LINE `sub` are both opaque strings up to ~50 chars.
            // 191 keeps us inside utf8mb4 unique-index limits without bloat.
            $table->string('provider_sub', 191);
            $table->timestamp('blacklisted_at')->useCurrent();
            $table->string('reason', 60)->default('account_deleted');

            $table->unique(['provider', 'provider_sub']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_trial_blacklist');
    }
};
