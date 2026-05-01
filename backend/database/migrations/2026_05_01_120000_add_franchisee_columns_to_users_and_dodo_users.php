<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_franchisee + franchise_verified_at to users and dodo_users.
 *
 * Used by FP gating across PokedexController, CardService (fp_recipe / franchise / franchise_only),
 * OutfitController (fp_crown / fp_chef). Set via Filament admin toggle for now;
 * 母艦 webhook integration follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'dodo_users'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'is_franchisee')) {
                    $table->boolean('is_franchisee')->default(false)->after('tier_verified_at');
                }
                if (! Schema::hasColumn($tableName, 'franchise_verified_at')) {
                    $table->timestamp('franchise_verified_at')->nullable()->after('is_franchisee');
                }
            });

            // Index added separately so it's safe even if the column was pre-existing.
            $indexExists = collect(Schema::getIndexes($tableName))
                ->contains(fn ($i) => in_array('is_franchisee', $i['columns'] ?? [], true));
            if (! $indexExists) {
                Schema::table($tableName, fn (Blueprint $t) => $t->index('is_franchisee'));
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'dodo_users'] as $tableName) {
            $indexExists = collect(Schema::getIndexes($tableName))
                ->contains(fn ($i) => in_array('is_franchisee', $i['columns'] ?? [], true));
            if ($indexExists) {
                Schema::table($tableName, fn (Blueprint $t) => $t->dropIndex(['is_franchisee']));
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $cols = array_filter(['is_franchisee', 'franchise_verified_at'], fn ($c) => Schema::hasColumn($tableName, $c));
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
