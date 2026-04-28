<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Identity\DodoUserSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase D Wave 1 (ADR-007 §2.3) — 把既有 legacy users 全部 ensureMirror 一次。
 *
 * 為什麼需要這支 command：
 *
 *   - UserObserver 只會在「之後」的 saved 事件補 mirror，對 Phase A 已經寫進
 *     legacy users 表的舊資料無能為力。
 *   - 18 個 reference tables 的 pandora_user_uuid 欄位是 nullable，舊 row 也是 null。
 *   - 上線前要把這些「歷史資料」全部回填，否則 Wave 2 service 改用 uuid 查詢時
 *     看不到舊 row，使用者會「資料突然消失」。
 *
 * 這支 command 做兩件事，按順序：
 *
 *   1. 對每個 legacy User 呼叫 DodoUserSyncService::ensureMirror()
 *      → 補 user.pandora_user_uuid + create/sync DodoUser
 *   2. 對 17 個 single-user-column tables，UPDATE 補 pandora_user_uuid
 *      （從 users 表 join 過來），對 referrals 表補 referrer / referee 雙欄
 *
 * Idempotent — 重跑安全：
 *   - ensureMirror 對已有 uuid 的 user 不重簽
 *   - reference table UPDATE 加 `WHERE pandora_user_uuid IS NULL`，已補的不會再寫
 *
 * Flags：
 *   --dry            乾跑，只報數字不寫資料
 *   --chunk=500      每批處理多少 user（預設 500）
 *
 * 使用：
 *   php artisan identity:backfill-mirror
 *   php artisan identity:backfill-mirror --dry
 *
 * @see ADR-007 §2.3
 */
class IdentityBackfillMirror extends Command
{
    protected $signature = 'identity:backfill-mirror
        {--dry : Dry run — print stats only, write nothing}
        {--chunk=500 : Users per batch}';

    protected $description = 'Backfill DodoUser mirror + reference-table pandora_user_uuid for all legacy users';

    /** 17 個只有單一 user_id 欄位的 reference tables */
    private const SINGLE_USER_COLUMN_TABLES = [
        'daily_logs',
        'meals',
        'conversations',
        'user_summaries',
        'weekly_reports',
        'achievements',
        'food_discoveries',
        'usage_logs',
        'card_plays',
        'card_event_offers',
        'daily_quests',
        'store_visits',
        'journey_advances',
        'analytics_events',
        'push_tokens',
        'client_errors',
        'rating_prompt_events',
        'paywall_events',
    ];

    public function handle(DodoUserSyncService $sync): int
    {
        $dry = (bool) $this->option('dry');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($dry) {
            $this->warn('[dry-run] no rows will be written');
        }

        // ── Step 1: ensureMirror for every legacy user ──
        $totalUsers = User::query()->count();
        $this->info("Step 1/2: ensureMirror across {$totalUsers} legacy users (chunk={$chunk})");

        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $created = 0;
        $alreadyHadUuid = 0;
        $errors = 0;

        User::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use ($sync, $dry, &$created, &$alreadyHadUuid, &$errors, $bar) {
                foreach ($users as $user) {
                    try {
                        if (! empty($user->pandora_user_uuid)) {
                            $alreadyHadUuid++;
                        }

                        if (! $dry) {
                            $sync->ensureMirror($user);
                        }

                        if (empty($user->pandora_user_uuid) || $alreadyHadUuid === 0) {
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->error("  user_id={$user->id} failed: {$e->getMessage()}");
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("  · already had uuid: {$alreadyHadUuid}");
        $this->info('  · processed: '.($totalUsers - $errors));
        if ($errors > 0) {
            $this->warn("  · errors: {$errors}");
        }

        // ── Step 2: reference tables backfill via UPDATE ... JOIN ──
        $this->info('Step 2/2: backfill reference tables pandora_user_uuid');

        $refStats = [];
        foreach (self::SINGLE_USER_COLUMN_TABLES as $table) {
            $count = $this->backfillSingleColumnTable($table, $dry);
            $refStats[$table] = $count;
            $this->line(sprintf('  · %-22s %d rows', $table, $count));
        }

        // referrals 雙欄
        $referrer = $this->backfillReferralColumn('pandora_referrer_uuid', 'referrer_id', $dry);
        $referee = $this->backfillReferralColumn('pandora_referee_uuid', 'referee_id', $dry);
        $this->line(sprintf('  · %-22s referrer=%d referee=%d', 'referrals', $referrer, $referee));

        $totalRefRows = array_sum($refStats) + $referrer + $referee;
        $this->info("Done. Reference rows backfilled: {$totalRefRows}".($dry ? ' (dry)' : ''));

        return self::SUCCESS;
    }

    /**
     * 把 reference table 的 pandora_user_uuid 從 users 表抄過來。
     *
     * 為什麼按 user 分組批次處理而不用 UPDATE...JOIN：
     *   - MariaDB / MySQL 支援 multi-table UPDATE，sqlite 不支援；CI 跑 sqlite。
     *   - 改成按 user 分組：先撈這個 user 的 uuid，再對該 user 的 reference rows
     *     做單表 UPDATE。每 user 一條 query，N=user 數量；對 user 數比 row 數
     *     小很多的場景（典型 Phase A 上線前）足夠快。
     *   - 不會觸發 Eloquent observer，因為走 query builder 直寫。
     */
    private function backfillSingleColumnTable(string $table, bool $dry): int
    {
        $count = 0;

        // 只挑「target row 缺 uuid 但對應 user 已有 uuid」的組
        DB::table('users')
            ->whereNotNull('pandora_user_uuid')
            ->select('id', 'pandora_user_uuid')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($table, $dry, &$count) {
                foreach ($users as $u) {
                    $q = DB::table($table)
                        ->where('user_id', $u->id)
                        ->whereNull('pandora_user_uuid');

                    $count += $dry
                        ? $q->count()
                        : $q->update(['pandora_user_uuid' => $u->pandora_user_uuid]);
                }
            });

        return $count;
    }

    private function backfillReferralColumn(string $uuidCol, string $idCol, bool $dry): int
    {
        $count = 0;

        DB::table('users')
            ->whereNotNull('pandora_user_uuid')
            ->select('id', 'pandora_user_uuid')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($uuidCol, $idCol, $dry, &$count) {
                foreach ($users as $u) {
                    $q = DB::table('referrals')
                        ->where($idCol, $u->id)
                        ->whereNull($uuidCol);

                    $count += $dry
                        ? $q->count()
                        : $q->update([$uuidCol => $u->pandora_user_uuid]);
                }
            });

        return $count;
    }
}
