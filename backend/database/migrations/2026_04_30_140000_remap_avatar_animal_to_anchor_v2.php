<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anchor v2 (方向1 手繪棉花紙質感) lineup is the集團統一 11 species.
 * Existing users with hamster / shiba / tuxedo are remapped to the closest
 * temperament match in the new lineup; their other state (level, xp, outfit)
 * is unaffected.
 *
 * Mapping (rationale):
 *   hamster (積攢/小累積)  → bear   (沉穩、療癒；積累情緒最近)
 *   shiba   (陪伴/熱血)    → dog    (1:1 直接對應，新 catalog 用通稱 dog)
 *   tuxedo  (優雅/自由)    → cat    (cat 系細分回主貓，賓士只是花色)
 *
 * Anyone already on rabbit/cat/bear/fox/dinosaur/penguin stays put.
 */
return new class extends Migration
{
    private const REMAP = [
        'hamster' => 'bear',
        'shiba' => 'dog',
        'tuxedo' => 'cat',
    ];

    public function up(): void
    {
        foreach (self::REMAP as $from => $to) {
            DB::table('users')
                ->where('avatar_animal', $from)
                ->update(['avatar_animal' => $to]);
        }
    }

    public function down(): void
    {
        // Irreversible by design — once mapped to bear/dog/cat we can't tell
        // which legacy species the user originally had. If rollback is needed,
        // restore from a pre-migration DB snapshot.
    }
};
