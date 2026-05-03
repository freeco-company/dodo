<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DodoUser;
use App\Services\AppConfigService;
use App\Services\Conversion\ConversionEventPublisher;
use App\Services\Conversion\LifecycleClient;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/bootstrap — single-call hydration for client.
 * Returns runtime app_config (paywall, disclaimer, push templates), plus
 * the current user's entitlements snapshot if authenticated, plus the
 * lifecycle / franchise-CTA decision (ADR-003 §2.3).
 */
class BootstrapController extends Controller
{
    /**
     * Lifecycle stages that should see the franchise consultation CTA.
     *
     * 2026-05-02 紅線收緊（meal CLAUDE.md「商業紅線」§ + memory feedback_meal_independent_app_no_franchise_push）：
     *   原 ['loyalist', 'applicant', 'franchisee_self_use'] 中的 `loyalist` 移除。
     *
     *   原因：loyalist 升級門檻是「連用 14 天」（純 in-app activity，不需要母艦消費過），
     *   違反新紅線「meal 對未在婕樂纖消費過的用戶 zero 加盟 CTA」。記錄飲食 / 走路用一用
     *   突然被推加盟會讓用戶反感，傷害 App 獨立性。
     *
     *   保留：
     *   - applicant（用戶已主動提交諮詢表單 = 用戶主動 → 自然回應）
     *   - franchisee_self_use（母艦下單過 = 符合「母艦消費過」紅線）
     *
     *   不變：
     *   - franchisee_active 仍走 operator portal hook（不露 CTA banner）
     *   - opt-out silence flag 仍硬擋（多一道保險）
     *
     * 公平交易法紅線（ADR-003 §7 / ADR-008 §7）：
     *   不可在 CTA 文案中出現「下線 / 分潤 / 推薦獎金 / 招募」等多層次傳銷暗示詞。
     *   ADR-008 新增禁字：「合作夥伴」「升級加盟方案」（過於曖昧）。
     *   後端只送 url + boolean，文案由前端 hardcode 中性詞（「自用回本 / 省錢 / 親友合購」）。
     */
    private const CTA_ELIGIBLE_STAGES = ['applicant', 'franchisee_self_use'];

    /**
     * Stages that should see the operator portal hook (段 2 鉤子，ADR-008 §3.3).
     * 目前只有 franchisee_active；franchisee_self_use 仍走 CTA banner（差異化文案）。
     */
    private const OPERATOR_PORTAL_STAGES = ['franchisee_active'];

    public function __construct(
        private readonly AppConfigService $config,
        private readonly EntitlementsService $entitlements,
        private readonly ConversionEventPublisher $conversion,
        private readonly LifecycleClient $lifecycle,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Sanctum-optional: try to resolve user from bearer token, but never error.
        $user = $request->user('sanctum') ?? $request->user();

        // ADR-003 §2.3: fire app.opened on every bootstrap call for an
        // authenticated user (anon callers don't have a uuid → skipped).
        $uuid = $user?->pandora_user_uuid;
        if (is_string($uuid) && $uuid !== '') {
            $this->conversion->publish($uuid, 'app.opened', [
                'source' => 'bootstrap',
            ]);
        }

        // SPEC-progress-ritual-v1 PR #9 — first-open-of-day season reveal.
        // Idempotent at RitualDispatcher level (season_reveal:user:release_id),
        // so calling on every bootstrap is safe — fires once per release.
        if ($user !== null) {
            try {
                app(\App\Services\Ritual\SeasonRevealService::class)->checkAndFireForUser($user);
            } catch (\Throwable $e) { /* fail-soft */ }
        }

        return response()->json([
            'app_config' => [
                'paywall' => $this->config->get('paywall'),
                'disclaimer' => $this->config->get('disclaimer'),
                'push_templates' => $this->config->get('push_templates'),
                'tier_limits' => $this->config->get('tier_limits'),
            ],
            // Pre-launch kill switch — client compares own build to min_*; if
            // below it MUST force upgrade (block the app). recommended_* is a
            // soft nag. Defaults to 1 in config/services.php so an unset env
            // never accidentally locks anyone out.
            'app_version' => [
                'min_ios_build' => (int) config('services.app.min_ios_build'),
                'recommended_ios_build' => (int) config('services.app.recommended_ios_build'),
                'min_android_build' => (int) config('services.app.min_android_build'),
                'recommended_android_build' => (int) config('services.app.recommended_android_build'),
            ],
            'entitlements' => $user ? $this->entitlements->get($user) : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'membership_tier' => $user->membership_tier,
                'subscription_type' => $user->subscription_type,
            ] : null,
            'lifecycle' => $this->lifecycleBlock(is_string($uuid) ? $uuid : null),
        ]);
    }

    /**
     * Build the lifecycle response block. Always returned (even for anon)
     * so the client can rely on a stable response shape.
     *
     * ADR-008 §2.1 §3.3: response 多送一個 `show_operator_portal` boolean，
     * 給段 2「想擴大經營」鉤子用（franchisee_active 才為 true）。
     *
     * UX sensitivity (4 條 user constraint)：使用者若已主動 opt-out
     * （dodo_users.franchise_cta_silenced = true），即使後端 lifecycle stage 算出
     * loyalist / applicant，也**不**回 show_franchise_cta=true。這代表使用者
     * 「我不想被推銷」的強訊號，prefer 客人感受 over 漏斗 KPI。
     *
     * @return array{status: string, show_franchise_cta: bool, show_operator_portal: bool, franchise_url: string}
     */
    private function lifecycleBlock(?string $pandoraUserUuid): array
    {
        $status = ($pandoraUserUuid !== null && $pandoraUserUuid !== '')
            ? $this->lifecycle->getStatus($pandoraUserUuid)
            : LifecycleClient::DEFAULT_STAGE;

        $stageEligible = in_array($status, self::CTA_ELIGIBLE_STAGES, true);
        $userSilenced = $pandoraUserUuid !== null && $pandoraUserUuid !== ''
            && DodoUser::query()
                ->whereKey($pandoraUserUuid)
                ->value('franchise_cta_silenced') === true;

        return [
            'status' => $status,
            'show_franchise_cta' => $stageEligible && ! $userSilenced,
            'show_operator_portal' => in_array($status, self::OPERATOR_PORTAL_STAGES, true),
            'franchise_url' => (string) config(
                'services.pandora_conversion.franchise_url',
                'https://js-store.com.tw/franchise/consult',
            ),
        ];
    }
}
