<?php

namespace App\Console\Commands;

use App\Models\DailyLog;
use App\Models\User;
use App\Services\Conversion\ConversionEventPublisher;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * `php artisan demo:franchise-funnel`
 *
 * 在本機從零跑完整 ADR-003 「愛用者 → 加盟」漏斗 demo，按時序印出 8 個 step
 * 的 timeline，最後驗證 lifecycle = loyalist + show_franchise_cta = true。
 *
 * 為什麼要這個 command：
 *   - 朵朵 + py-service 的 conversion 串接觀察點散在 8+ 處（bootstrap、checkin、
 *     franchise CTA、py-service event ingest、lifecycle GET、cache bust...）。
 *     每次手動戳 curl 重現需要 30 分鐘以上，第三人接手很容易漏 step。
 *   - 給 PM / QA / 第三方工程師快速「看一次」整條漏斗的工具。
 *
 * 為什麼 `--force-loyalist`：
 *   - py-service 的 loyalist transition 規則目前依賴「母艦復購 ≥ 2」訊號，
 *     dev 環境母艦 client 是 stub，自然 transition 不會發生。
 *   - 任何 demo 都需要一個「強制升等」入口才能演到 step 8。
 *
 * 不在 CI 跑：用 `@Group('demo')` tag，配 phpunit.xml 預設 exclude（見
 * tests/Feature/Console/DemoFranchiseFunnelTest.php 註解）。
 */
class DemoFranchiseFunnel extends Command
{
    protected $signature = 'demo:franchise-funnel
        {--clean : 開跑前清掉 demo user 與相關 reference rows}
        {--force-loyalist : 跳過 lifecycle 自然規則，直接 hit py-service admin endpoint 升 loyalist}
        {--show-http : 印每個 HTTP request / response（debug 用）}';

    protected $description = '從零跑完整 ADR-003 愛用者→加盟漏斗 demo（建 user / 7 天打卡 / fire 事件 / 驗 CTA）';

    private const DEMO_EMAIL = 'demo@dodo.local';

    private const TOTAL_STEPS = 8;

    private int $stepNum = 0;

    public function handle(
        ConversionEventPublisher $publisher,
        LifecycleClient $lifecycle,
    ): int {
        $this->newLine();
        $this->info('==========================================');
        $this->info('  ADR-003 Franchise Funnel Demo');
        $this->info('==========================================');
        $this->newLine();

        if ($this->option('clean')) {
            $this->cleanup();
        }

        // ── Step 1: Create demo user ────────────────────────────────────────
        $this->step('Creating demo user @'.self::DEMO_EMAIL);
        $user = $this->createDemoUser();
        $this->ok("user.id = {$user->id}, pandora_user_uuid = {$user->pandora_user_uuid}");

        $uuid = (string) $user->pandora_user_uuid;
        if ($uuid === '') {
            $this->error('demo user has no pandora_user_uuid; UserObserver may be misconfigured.');

            return self::FAILURE;
        }

        // Pre-flight: warn if publisher / lifecycle client aren't configured.
        if (! $publisher->isEnabled()) {
            $this->warn('  PANDORA_CONVERSION_BASE_URL / SHARED_SECRET 未設 — events 會 noop。');
            $this->warn('  Demo 仍會跑完，但 step 8 大概率拿到 visitor。建議設好 env 再跑。');
        }

        // ── Step 2: Fire app.opened ─────────────────────────────────────────
        $this->step('Fire app.opened event');
        $publisher->publish($uuid, 'app.opened', ['source' => 'demo:franchise-funnel']);
        $this->ok('outbox queued');
        $this->sleep(1);

        // ── Step 3: Simulate 7-day checkin streak ────────────────────────────
        $this->step('Simulate 7-day checkin streak (write daily_logs directly)');
        $this->seedStreak($user, 7);
        $this->ok('7 daily_logs created');

        // ── Step 4: Fire engagement.deep ─────────────────────────────────────
        $this->step('Fire engagement.deep (clears lifecycle cache)');
        $publisher->publish($uuid, 'engagement.deep', [
            'streak_days' => 7,
            'reason' => 'demo_seeded_streak',
        ]);
        $this->ok('outbox queued + lifecycle cache forgotten');
        $this->sleep(2);

        // ── Step 5: Read lifecycle (bypassCache to see py-service truth) ────
        $this->step('Read lifecycle from py-service (bypassCache)');
        $stage = $lifecycle->getStatus($uuid, bypassCache: true);
        $this->ok("status = {$stage}");

        // ── Step 6: Optional force-loyalist ─────────────────────────────────
        $this->step('Force loyalist (admin endpoint)');
        if ($this->option('force-loyalist')) {
            try {
                $this->forceLoyalist($uuid);
                $lifecycle->forget($uuid);
                $this->ok('forced to loyalist via py-service admin endpoint');
            } catch (Throwable $e) {
                $this->error('  force-loyalist failed: '.$e->getMessage());
                $this->warn('  繼續跑 demo，但 step 8 預期會 fail（status 不會升 loyalist）。');
            }
        } else {
            $this->line('  (skipped — pass --force-loyalist to actually升等)');
            $this->warn('  Without --force-loyalist, dev 環境 lifecycle 自然規則不會 fire loyalist。');
        }

        // ── Step 7: Fire franchise.cta_view ──────────────────────────────────
        $this->step('Fire franchise.cta_view');
        $publisher->publish($uuid, 'franchise.cta_view', ['source' => 'demo']);
        $this->ok('outbox queued');
        $this->sleep(1);

        // ── Step 8: Verify lifecycle + CTA visibility ────────────────────────
        $this->step('Verify lifecycle & franchise CTA');
        $finalStage = $lifecycle->getStatus($uuid, bypassCache: true);
        $showCta = in_array($finalStage, ['loyalist', 'applicant'], true);
        $franchiseUrl = (string) config('services.pandora_conversion.franchise_url');

        $this->line("  status = {$finalStage}");
        $this->line('  show_franchise_cta = '.($showCta ? 'true' : 'false'));
        $this->line("  franchise_url = {$franchiseUrl}");

        $this->newLine();
        $this->info('==========================================');
        if ($showCta && $finalStage === 'loyalist') {
            $this->info('  Demo complete. Open frontend with this user');
            $this->info("  logged in ({$user->email}) to see CTA.");
        } else {
            $this->warn("  Demo finished, but final stage = {$finalStage} (expected loyalist).");
            $this->warn('  Re-run with --force-loyalist or check py-service connectivity.');
        }
        $this->info('==========================================');
        $this->newLine();

        return self::SUCCESS;
    }

    private function step(string $msg): void
    {
        $this->stepNum++;
        $this->info(sprintf('Step %d/%d: %s', $this->stepNum, self::TOTAL_STEPS, $msg));
    }

    private function ok(string $msg): void
    {
        $this->line("  ✓ {$msg}");
    }

    private function sleep(int $seconds): void
    {
        if ($this->option('show-http')) {
            $this->line("  (sleep {$seconds}s)");
        }
        // Skip real sleep when running under tests to keep them fast.
        if (! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }

    /**
     * Create or fetch the demo user. UserObserver auto-mints pandora_user_uuid.
     */
    private function createDemoUser(): User
    {
        $existing = User::where('email', self::DEMO_EMAIL)->first();
        if ($existing) {
            $this->line('  (reusing existing demo user — pass --clean to start fresh)');

            return $existing;
        }

        return User::create([
            'name' => 'Demo User',
            'email' => self::DEMO_EMAIL,
            'password' => Hash::make('demo-'.Str::random(16)),
            'subscription_tier' => 'free',
            'pandora_user_uuid' => (string) Str::uuid7(),
        ]);
    }

    /**
     * Seed N consecutive daily_logs ending today. Bypasses CheckinService so
     * we don't double-fire engagement.deep (the demo controls when that fires).
     */
    private function seedStreak(User $user, int $days): void
    {
        $today = Carbon::today();
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            DailyLog::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'date' => $date,
                ],
                [
                    'pandora_user_uuid' => $user->pandora_user_uuid,
                    'total_score' => 60,
                    'meals_logged' => 3,
                    'water_ml' => 1500,
                    'xp_earned' => 10,
                ],
            );
        }
    }

    /**
     * Hit py-service admin route to force loyalist transition.
     *
     * Endpoint requires JWT with `lifecycle:write` scope. We don't sign one in
     * dodo backend (would mean importing platform JWT signer), so we expect it
     * to be pre-baked into env (`PANDORA_DEMO_ADMIN_JWT`). Document this caveat
     * in the PR description.
     */
    private function forceLoyalist(string $uuid): void
    {
        $base = rtrim((string) config('services.pandora_conversion.base_url'), '/');
        $jwt = (string) config('services.pandora_conversion.demo_admin_jwt');

        if ($base === '' || $jwt === '') {
            throw new \RuntimeException(
                'PANDORA_CONVERSION_BASE_URL or PANDORA_DEMO_ADMIN_JWT missing — see config/services.php',
            );
        }

        $url = $base."/api/v1/users/{$uuid}/lifecycle/transition";
        if ($this->option('show-http')) {
            $this->line("  → POST {$url}");
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$jwt,
            'Accept' => 'application/json',
        ])
            ->timeout(10)
            ->post($url, [
                'to_status' => 'loyalist',
                'metadata' => ['source' => 'demo:franchise-funnel'],
            ]);

        if ($this->option('show-http')) {
            $this->line('  ← '.$response->status().' '.substr((string) $response->body(), 0, 200));
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'py-service returned %d: %s',
                $response->status(),
                substr((string) $response->body(), 0, 200),
            ));
        }
    }

    /**
     * Tear down demo user + cascading rows. Wraps in a transaction so a partial
     * failure leaves the DB in its prior state.
     */
    private function cleanup(): void
    {
        $this->info('--clean: removing existing demo user and its rows');
        DB::transaction(function () {
            $user = User::where('email', self::DEMO_EMAIL)->first();
            if (! $user) {
                $this->line('  (no existing demo user — nothing to clean)');

                return;
            }

            $uuid = (string) $user->pandora_user_uuid;
            if ($uuid !== '') {
                DailyLog::where('pandora_user_uuid', $uuid)->delete();
                Cache::forget("conversion:engagement_deep_fired:{$uuid}");
                Cache::forget(app(LifecycleClient::class)->cacheKey($uuid));
            }
            // user_id-keyed rows handled by FK cascadeOnDelete on most reference tables
            $user->delete();
            $this->line('  ✓ cleaned demo user + cascading rows');
        });
        $this->newLine();
    }
}
