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
     * (ADR-003 §2.3 — loyalist 是「愛用者」階段、applicant 是已主動表態想了解加盟的人，
     *  兩者都還沒成為 franchisee 所以 CTA 仍要露出)。
     *
     * 公平交易法紅線（dodo CLAUDE.md / ADR-003 §6）：
     *   不可在 CTA 文案中出現「下線 / 分潤 / 推薦獎金 / 招募」等多層次傳銷暗示詞。
     *   後端只送 url + boolean，文案由前端 hardcode 中性詞。
     */
    private const CTA_ELIGIBLE_STAGES = ['loyalist', 'applicant'];

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

        return response()->json([
            'app_config' => [
                'paywall' => $this->config->get('paywall'),
                'disclaimer' => $this->config->get('disclaimer'),
                'push_templates' => $this->config->get('push_templates'),
                'tier_limits' => $this->config->get('tier_limits'),
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
     * @return array{status: string, show_franchise_cta: bool, franchise_url: string}
     */
    private function lifecycleBlock(?string $pandoraUserUuid): array
    {
        $status = ($pandoraUserUuid !== null && $pandoraUserUuid !== '')
            ? $this->lifecycle->getStatus($pandoraUserUuid)
            : LifecycleClient::DEFAULT_STAGE;

        return [
            'status' => $status,
            'show_franchise_cta' => in_array($status, self::CTA_ELIGIBLE_STAGES, true),
            'franchise_url' => (string) config(
                'services.pandora_conversion.franchise_url',
                'https://js-store.com.tw/franchise/consult',
            ),
        ];
    }
}
