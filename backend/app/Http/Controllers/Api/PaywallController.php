<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppConfigService;
use App\Services\PaywallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaywallController extends Controller
{
    public function __construct(
        private readonly PaywallService $service,
        private readonly AppConfigService $config,
    ) {}

    /**
     * Slimmed port of ai-game/src/services/paywall.ts getPaywallView.
     * Returns the paywall content (from app_config, falls back to defaults)
     * plus current user's trial / subscription summary.
     */
    public function view(Request $request): JsonResponse
    {
        $user = $request->user();
        $trigger = $request->query('trigger');
        $content = $this->config->get('paywall') ?? $this->defaultContent();

        $sub = $user->subscription_type ?? 'none';
        /** @var \Illuminate\Support\Carbon|null $trialExpires */
        $trialExpires = $user->trial_expires_at;
        $trialState = match (true) {
            $user->trial_started_at === null => 'never_started',
            $trialExpires !== null && $trialExpires->isFuture() => 'active',
            $sub !== 'none' => 'converted',
            default => 'expired',
        };
        $trialDaysLeft = $trialExpires !== null && $trialExpires->isFuture()
            ? (int) ceil(now()->diffInDays($trialExpires, false))
            : 0;

        return response()->json([
            'variant_key' => 'v1',
            'content' => $content,
            'user' => [
                'trial_state' => $trialState,
                'trial_days_left' => $trialDaysLeft,
                'on_trial' => $trialState === 'active',
                'subscription_type' => $sub,
            ],
            'trigger' => is_string($trigger) ? $trigger : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function defaultContent(): array
    {
        return [
            'hero' => [
                'eyebrow' => '解鎖完整體驗',
                'title' => '陪你走完 21 天',
                'subtitle' => '訂閱解鎖每日 30 張拍照辨識、所有店家、無限 AI 陪跑',
            ],
            'tiers' => [
                [
                    'key' => 'app_yearly',
                    'label' => '年訂閱',
                    'price_twd' => 2490,
                    'period_label' => '一年（每月約 NT$208）',
                    'highlights' => ['省 NT$990（28% off）', '專屬年度徽章'],
                    'cta' => '開始 7 天免費試用',
                    'badge' => '最划算',
                ],
                [
                    'key' => 'app_monthly',
                    'label' => '月訂閱',
                    'price_twd' => 290,
                    'period_label' => '每月',
                    'highlights' => ['每日 30 張拍照辨識', '無限 AI 陪跑'],
                    'cta' => '開始 7 天免費試用',
                ],
            ],
            'trust_strip' => ['7 天免費試用', '隨時取消', 'App Store 安全付款'],
            'legal_footnote' => '訂閱會在試用期結束自動扣款，可在 App Store / Google Play 隨時取消。本服務非醫療建議。',
        ];
    }

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', PaywallService::KINDS)],
            'trigger' => ['nullable', 'string', 'max:48'],
            'properties' => ['nullable', 'array'],
        ]);
        $this->service->logEvent(
            $request->user(),
            $data['kind'],
            $data['trigger'] ?? null,
            $data['properties'] ?? []
        );
        return response()->json(['ok' => true]);
    }
}
