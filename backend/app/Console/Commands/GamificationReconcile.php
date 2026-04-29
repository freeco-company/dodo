<?php

namespace App\Console\Commands;

use App\Services\Gamification\AchievementMirror;
use App\Services\Gamification\GroupProgressionMirror;
use App\Services\Gamification\OutfitMirror;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot reconciliation: pull a single user's full gamification snapshot
 * from py-service and replay it through the local mirror services so the
 * local `users.{level,xp,outfits_owned}` + `achievements` table match what
 * py-service knows.
 *
 * Use cases:
 *   - Ops manually fixing a user whose webhook chain dropped events
 *     (worker crash, network partition, payload validation reject).
 *   - Manual smoke after deploys to check sync round-trip.
 *
 * NOT for periodic backfill — that's what the webhook chain is for. If you
 * find yourself running this against many users, the right fix is upstream
 * (worker reliability, dlq processing) not a cron of this command.
 *
 * @see py-service GET /api/v1/internal/gamification/users/{uuid}/sync (PR #14)
 */
class GamificationReconcile extends Command
{
    protected $signature = 'gamification:reconcile
        {uuid : Pandora user UUID to reconcile}
        {--dry-run : Show snapshot diff without writing local mirror}';

    protected $description = 'Pull a user\'s gamification snapshot from py-service and replay through local mirrors';

    public function handle(
        GroupProgressionMirror $progressionMirror,
        AchievementMirror $achievementMirror,
        OutfitMirror $outfitMirror,
    ): int {
        $base = rtrim((string) config('services.pandora_gamification.base_url'), '/');
        $secret = (string) config('services.pandora_gamification.shared_secret');
        if ($base === '' || $secret === '') {
            $this->error('PANDORA_GAMIFICATION_BASE_URL / SHARED_SECRET not configured.');

            return self::FAILURE;
        }
        $uuid = (string) $this->argument('uuid');
        if ($uuid === '') {
            $this->error('uuid argument required');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $url = $base.'/api/v1/internal/gamification/users/'.$uuid.'/sync';
        $resp = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout((int) config('services.pandora_gamification.timeout', 5))
            ->retry(2, 200, throw: false)
            ->get($url);

        if (! $resp->successful()) {
            $this->error(sprintf('sync fetch failed: status=%d body=%s', $resp->status(), substr((string) $resp->body(), 0, 200)));

            return self::FAILURE;
        }

        $snap = (array) $resp->json();
        $prog = is_array($snap['progression'] ?? null) ? $snap['progression'] : [];
        $achievements = is_array($snap['achievements'] ?? null) ? $snap['achievements'] : [];
        $outfits = is_array($snap['outfits'] ?? null) ? $snap['outfits'] : [];

        $this->line(sprintf(
            'snapshot: level=%d xp=%d achievements=%d outfits=%d',
            (int) ($prog['group_level'] ?? 0),
            (int) ($prog['total_xp'] ?? 0),
            count($achievements),
            count($outfits),
        ));

        if ($dryRun) {
            $this->info('--dry-run: no local writes');

            return self::SUCCESS;
        }

        // Progression — translate sync shape to webhook shape
        $levelChanged = $progressionMirror->applyLevelUp($uuid, [
            'new_level' => (int) ($prog['group_level'] ?? 0),
            'total_xp' => (int) ($prog['total_xp'] ?? 0),
        ]);

        // Achievements — replay each as if the webhook just fired
        $achNew = 0;
        foreach ($achievements as $a) {
            if (! is_array($a)) {
                continue;
            }
            $applied = $achievementMirror->applyAwarded($uuid, [
                'code' => (string) ($a['code'] ?? ''),
                'name' => (string) ($a['code'] ?? ''),  // sync doesn't include localised name; code is fine for mirror
                'tier' => (string) ($a['tier'] ?? ''),
                'source_app' => (string) ($a['source_app'] ?? ''),
                'occurred_at' => (string) ($a['awarded_at'] ?? ''),
            ]);
            if ($applied) {
                $achNew++;
            }
        }

        // Outfits — batch
        $outfitCodes = array_values(array_filter(array_map(
            fn ($o) => is_array($o) ? (string) ($o['code'] ?? '') : '',
            $outfits,
        ), fn ($c) => $c !== ''));
        $outfitNew = $outfitMirror->applyUnlocked($uuid, [
            'codes' => $outfitCodes,
            'awarded_via' => 'reconcile',
        ]);

        $this->info(sprintf(
            'reconciled: level_changed=%s achievements_new=%d outfits_new=%d',
            $levelChanged ? 'yes' : 'no',
            $achNew,
            $outfitNew,
        ));

        return self::SUCCESS;
    }
}
