<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Lifecycle stages that should see the franchise consultation CTA
     * (ADR-008 §2.1 §4 — 加盟自用回本 CTA 對 loyalist / applicant / franchisee_self_use 顯示).
     *
     * 對 self_use 仍露 banner — 文案改成「想擴大經營？」入口（前端做差異化）。
     * franchisee_active 不顯示 banner（已是進階經營者）；改顯示 operator portal 鉤子。
     *
     * 公平交易法紅線（dodo CLAUDE.md / ADR-003 §7 / ADR-008 §7）：
     *   不可在 CTA 文案中出現「下線 / 分潤 / 推薦獎金 / 招募」等多層次傳銷暗示詞。
     *   ADR-008 新增禁字：「合作夥伴」「升級加盟方案」（過於曖昧）。
     *   後端只送 url + boolean，文案由前端 hardcode 中性詞（「自用回本 / 省錢 / 親友合購」）。
     */
    private const CTA_ELIGIBLE_STAGES = ['loyalist', 'applicant', 'franchisee_self_use'];

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

        $appConfig = [
            'paywall' => $this->config->get('paywall'),
            'disclaimer' => $this->config->get('disclaimer'),
            'push_templates' => $this->config->get('push_templates'),
            'tier_limits' => $this->config->get('tier_limits'),
        ];

        $settings = $user ? [
            'push_enabled' => (bool) ($user->push_enabled ?? false),
            'dietary_type' => $user->dietary_type,
            'allergies' => $user->allergies ?? [],
            'dislike_foods' => $user->dislike_foods ?? [],
            'favorite_foods' => $user->favorite_foods ?? [],
            'activity_level' => $user->activity_level,
            'target_weight_kg' => $user->target_weight_kg,
            'daily_water_goal_ml' => (int) ($user->daily_water_goal_ml ?? 3000),
        ] : null;

        return response()->json([
            'config' => $appConfig,
            'app_config' => $appConfig,
            'content_version' => $this->config->contentVersion(),
            'settings' => $settings,
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
     * @return array{status: string, show_franchise_cta: bool, show_operator_portal: bool, franchise_url: string}
     */
    private function lifecycleBlock(?string $pandoraUserUuid): array
    {
        $status = ($pandoraUserUuid !== null && $pandoraUserUuid !== '')
            ? $this->lifecycle->getStatus($pandoraUserUuid)
            : LifecycleClient::DEFAULT_STAGE;

        return [
            'status' => $status,
            'show_franchise_cta' => in_array($status, self::CTA_ELIGIBLE_STAGES, true),
            'show_operator_portal' => in_array($status, self::OPERATOR_PORTAL_STAGES, true),
            'franchise_url' => (string) config(
                'services.pandora_conversion.franchise_url',
                'https://js-store.com.tw/franchise/consult',
            ),
        ];
    }
}
