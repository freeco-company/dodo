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
 * 在本機從零跑完整 ADR-008 兩段「自用客 → 經營者」漏斗 demo，按時序印出 timeline，
 * 最後驗證 lifecycle 與對應的 CTA / operator portal 顯示狀態。
 *
 * ADR-008 5 stage flow：
 *   visitor → loyalist (連用 14 天) → applicant (CTA click 或母艦諮詢)
 *           → franchisee_self_use (母艦首單 ≥ NT$6,600 webhook)
 *           → franchisee_active (月進貨達標 OR 點仙女學院經營者入口)
 *
 * 為什麼要這個 command：第三人接手 / QA 快速看一次整條漏斗，免戳 curl。
 *
 * Force flags（dev 環境母艦 stub 沒辦法自然 transition，要手動推）：
 *   --force-loyalist     直接 hit py-service admin endpoint 升 loyalist
 *   --force-self-use     模擬母艦 first_order webhook → franchisee_self_use
 *   --force-active       模擬 3 個月進貨統計 → franchisee_active
 *
 * 不在 CI 跑：用 `@Group('demo')` tag，配 phpunit.xml 預設 exclude（見
 * tests/Feature/Console/DemoFranchiseFunnelTest.php 註解）。
 */
class DemoFranchiseFunnel extends Command
{
    protected $signature = 'demo:franchise-funnel
        {--clean : 開跑前清掉 demo user 與相關 reference rows}
        {--force-loyalist : hit py-service admin endpoint 升 loyalist}
        {--force-self-use : 模擬母艦 first_order webhook → franchisee_self_use}
        {--force-active : 模擬 3 個月進貨統計 → franchisee_active}
        {--show-http : 印每個 HTTP request / response（debug 用）}';

    protected $description = '從零跑完整 ADR-008 兩段加盟漏斗 demo（建 user / 14 天打卡 / fire 事件 / 驗 CTA + operator portal）';

    private const DEMO_EMAIL = 'demo@dodo.local';

    private const TOTAL_STEPS = 8;

    private int $stepNum = 0;

    public function handle(
        ConversionEventPublisher $publisher,
        LifecycleClient $lifecycle,
    ): int {
        $this->newLine();
        $this->info('==========================================');
        $this->info('  ADR-008 Franchise Funnel Demo（兩段漏斗）');
        $this->info('==========================================');
        $this->printTimelinePreview();
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

        // ── Step 3: Simulate 14-day checkin streak (ADR-008 §2.1 loyalist 門檻) ──
        $this->step('Simulate 14-day checkin streak (write daily_logs directly)');
        $this->seedStreak($user, 14);
        $this->ok('14 daily_logs created');

        // ── Step 4: Fire engagement.deep（ADR-008 §2.1：14 天連續使用） ────────
        $this->step('Fire engagement.deep (14-day, clears lifecycle cache)');
        $publisher->publish($uuid, 'engagement.deep', [
            'streak_days' => 14,
            'reason' => 'demo_seeded_streak',
        ]);
        $this->ok('outbox queued + lifecycle cache forgotten');
        $this->sleep(2);

        // ── Step 5: Read lifecycle (bypassCache to see py-service truth) ────
        $this->step('Read lifecycle from py-service (bypassCache)');
        $stage = $lifecycle->getStatus($uuid, bypassCache: true);
        $this->ok("status = {$stage}");

        // ── Step 6: Force ladder loyalist → self_use → active ──────────────
        $this->step('Force lifecycle ladder (per --force-* flags)');
        $targetStage = $this->forceLadder($lifecycle, $uuid);

        // ── Step 7: Fire franchise.cta_view ──────────────────────────────────
        $this->step('Fire franchise.cta_view');
        $publisher->publish($uuid, 'franchise.cta_view', ['source' => 'demo']);
        $this->ok('outbox queued');
        $this->sleep(1);

        // ── Step 8: Verify lifecycle + CTA / operator portal visibility ─────
        $this->step('Verify lifecycle & CTA / operator portal flags');
        $finalStage = $lifecycle->getStatus($uuid, bypassCache: true);
        // 與 BootstrapController::CTA_ELIGIBLE_STAGES / OPERATOR_PORTAL_STAGES 對齊
        $showCta = in_array($finalStage, ['loyalist', 'applicant', 'franchisee_self_use'], true);
        $showOperatorPortal = $finalStage === 'franchisee_active';
        $franchiseUrl = (string) config('services.pandora_conversion.franchise_url');

        $this->line("  status = {$finalStage}");
        $this->line('  show_franchise_cta = '.($showCta ? 'true' : 'false'));
        $this->line('  show_operator_portal = '.($showOperatorPortal ? 'true' : 'false'));
        $this->line("  franchise_url = {$franchiseUrl}");
        $this->line('  expected CTA copy = '.$this->expectedCopyFor($finalStage));

        $this->newLine();
        $this->info('==========================================');
        if ($targetStage !== null && $finalStage === $targetStage) {
            $this->info("  Demo complete. Final stage = {$finalStage}");
            $this->info("  Open frontend logged in as {$user->email} to see banner.");
        } elseif ($targetStage === null) {
            $this->info("  Demo finished (no --force-* flag). Final stage = {$finalStage}");
            $this->warn('  Without --force-*, dev 環境 lifecycle 自然規則不會 transition。');
        } else {
            $this->warn("  Demo finished, but final stage = {$finalStage} (expected {$targetStage}).");
            $this->warn('  Check py-service connectivity / admin endpoint.');
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
     * Walk the user up the ADR-008 lifecycle ladder according to --force-* flags.
     * Returns the highest stage we attempted to land on (or null if no flag set).
     *
     * Cumulative semantics: --force-active implies the user must already be
     * franchisee_self_use, which implies loyalist. We only POST to py-service
     * admin endpoint for the highest one — py-service is responsible for
     * materialising intermediate transitions; this is just a demo helper.
     */
    private function forceLadder(LifecycleClient $lifecycle, string $uuid): ?string
    {
        $target = null;
        if ($this->option('force-active')) {
            $target = 'franchisee_active';
        } elseif ($this->option('force-self-use')) {
            $target = 'franchisee_self_use';
        } elseif ($this->option('force-loyalist')) {
            $target = 'loyalist';
        }

        if ($target === null) {
            $this->line('  (skipped — pass --force-loyalist / --force-self-use / --force-active to upgrade)');
            $this->warn('  Without a --force-* flag, dev 環境 lifecycle 自然規則不會 transition。');

            return null;
        }

        try {
            $this->forceTransition($uuid, $target);
            $lifecycle->forget($uuid);
            $this->ok("forced to {$target} via py-service admin endpoint");
        } catch (Throwable $e) {
            $this->error("  force-{$target} failed: ".$e->getMessage());
            $this->warn('  繼續跑 demo，但 step 8 預期 stage 不會到目標值。');
        }

        return $target;
    }

    /**
     * Hit py-service admin route to force a specific transition.
     *
     * Endpoint requires JWT with `lifecycle:write` scope. We don't sign one in
     * dodo backend (would mean importing platform JWT signer), so we expect it
     * to be pre-baked into env (`PANDORA_DEMO_ADMIN_JWT`). Document this caveat
     * in the PR description.
     */
    private function forceTransition(string $uuid, string $toStatus): void
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
            $this->line("  → POST {$url} (to_status={$toStatus})");
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$jwt,
            'Accept' => 'application/json',
        ])
            ->timeout(10)
            ->post($url, [
                'to_status' => $toStatus,
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
     * Print the ADR-008 stage ladder so demo viewers see the funnel shape upfront.
     */
    private function printTimelinePreview(): void
    {
        $this->line('  ADR-008 lifecycle ladder（兩段漏斗）：');
        $this->line('    visitor → loyalist (連用 14 天)');
        $this->line('             → applicant (CTA click 或母艦諮詢表單)');
        $this->line('             → franchisee_self_use (母艦首單 ≥ NT$6,600 webhook)');
        $this->line('             → franchisee_active (月進貨達標 OR 點仙女學院經營者入口)');
    }

    /**
     * Front-end banner copy preview per stage (mirror frontend/public/app.js).
     * Pure presentation helper — no business logic.
     */
    private function expectedCopyFor(string $stage): string
    {
        return match ($stage) {
            'loyalist' => '「你已經連續使用 14 天，加盟自用回本只要 N 個月，要了解？」',
            'applicant' => '「諮詢加盟方案 — 自用客也能加盟省錢，看看回本試算 →」',
            'franchisee_self_use' => '「想擴大經營？看仙女學院經營者課程 →」',
            'franchisee_active' => '(no banner — operator portal hook only)',
            default => '(no banner)',
        };
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
