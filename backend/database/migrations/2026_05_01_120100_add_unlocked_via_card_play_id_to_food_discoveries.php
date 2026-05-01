<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which card_play unlocked each food discovery (for pokedex food detail modal).
 *
 * Nullable because pre-existing discoveries (and meal-scan unlocks) won't have one.
 * No FK constraint to keep the column safe across card_play retention pruning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_discoveries', function (Blueprint $table) {
            if (! Schema::hasColumn('food_discoveries', 'unlocked_via_card_play_id')) {
                $table->unsignedBigInteger('unlocked_via_card_play_id')->nullable()->after('is_shiny');
            }
        });

        $indexExists = collect(Schema::getIndexes('food_discoveries'))
            ->contains(fn ($i) => in_array('unlocked_via_card_play_id', $i['columns'] ?? [], true));
        if (! $indexExists) {
            Schema::table('food_discoveries', fn (Blueprint $t) => $t->index('unlocked_via_card_play_id'));
        }
    }

    public function down(): void
    {
        $indexExists = collect(Schema::getIndexes('food_discoveries'))
            ->contains(fn ($i) => in_array('unlocked_via_card_play_id', $i['columns'] ?? [], true));
        if ($indexExists) {
            Schema::table('food_discoveries', fn (Blueprint $t) => $t->dropIndex(['unlocked_via_card_play_id']));
        }
        Schema::table('food_discoveries', function (Blueprint $table) {
            if (Schema::hasColumn('food_discoveries', 'unlocked_via_card_play_id')) {
                $table->dropColumn('unlocked_via_card_play_id');
            }
        });
    }
};
