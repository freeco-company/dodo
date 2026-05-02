<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * SPEC-02 / 04 / 06 Phase 2 — push template registry + dispatcher.
 *
 * Composes the raw `PushService::send()` into named templates with:
 *   - 朵朵 voice copy
 *   - quiet-hours guard (22:00-08:00 Asia/Taipei) for non-streak templates
 *   - deep_link path passed via FCM `data` payload for client routing
 *   - tier gating where applicable (Paid-only nudges are gated here, not by
 *     the schedule — easier to test and to opt-out per-user later)
 *
 * Templates intentionally don't loop over users themselves; callers (artisan
 * commands or service hooks) iterate active users and call into here.
 */
class PushDispatcher
{
    public function __construct(
        private readonly PushService $push,
        private readonly EntitlementsService $entitlements,
    ) {}

    /**
     * Hard quiet-hours: 22:00-08:00 Asia/Taipei. Streak-warning templates
     * pass force=true so the 22:00-23:59 reminder still goes out.
     */
    private function inQuietHours(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('Asia/Taipei');
        $h = (int) $now->format('G');

        return $h < 8 || $h >= 22;
    }

    /**
     * SPEC-04 — weekly report ready (Sunday 20:00).
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function weeklyReportReady(User $user, string $weekEnd): array
    {
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }

        return $this->push->send(
            $user,
            '本週小報告出爐了 ✨',
            '朵朵幫妳整理好上週紀錄了，點開看看 🌱',
            ['template' => 'weekly_report_ready', 'deep_link' => '/report', 'week_end' => $weekEnd],
        );
    }

    /**
     * SPEC-02 §4 — fasting completion ack.
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function fastingCompleted(User $user, string $mode, int $elapsedMinutes): array
    {
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }
        $h = intdiv($elapsedMinutes, 60);

        return $this->push->send(
            $user,
            "完成 {$h} 小時斷食 ✨",
            "朵朵：「妳真的很有毅力 💪 記得補水」",
            ['template' => 'fasting_completed', 'deep_link' => '/fasting', 'mode' => $mode],
        );
    }

    /**
     * SPEC-02 §4 — pre-eat 30 minute reminder (paid only).
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function fastingPreEat(User $user, string $mode): array
    {
        if (! $this->entitlements->isPaid($user)) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'paid_only_template'];
        }
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }

        return $this->push->send(
            $user,
            '再 30 分鐘可以吃了 🌟',
            '想想這餐要吃什麼？要不要拍照記錄一下？',
            ['template' => 'fasting_pre_eat', 'deep_link' => '/fasting', 'mode' => $mode],
        );
    }

    /**
     * SPEC-02 §4 — streak-at-risk reminder (allowed inside 22:00-23:59).
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function fastingStreakAtRisk(User $user, int $streakDays): array
    {
        return $this->push->send(
            $user,
            '今天還沒開始斷食喔 🌱',
            "妳已經連續 {$streakDays} 天達標了，別斷在這 ✨",
            ['template' => 'fasting_streak_at_risk', 'deep_link' => '/fasting'],
        );
    }

    /**
     * SPEC-06 — seasonal limited content released today.
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function seasonalRelease(User $user, string $label): array
    {
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }

        return $this->push->send(
            $user,
            "{$label}上架了 ✨",
            '朵朵：「快去看看新的限定收藏 🌸」',
            ['template' => 'seasonal_release', 'deep_link' => '/cards-codex', 'label' => $label],
        );
    }

    /**
     * SPEC-06 — seasonal limited content closing in 7 days.
     *
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function seasonalExpiringSoon(User $user, string $label, int $daysLeft): array
    {
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }

        return $this->push->send(
            $user,
            "{$label}剩 {$daysLeft} 天 🌸",
            '還沒試過嗎？別錯過這次的限定 ✨',
            ['template' => 'seasonal_expiring_soon', 'deep_link' => '/cards-codex', 'label' => $label],
        );
    }

    /**
     * Catch-all logger for unknown template requests; useful for QA seeding
     * test pushes from the admin panel without hardcoding more methods.
     *
     * @param  array<string,mixed>  $extraData
     * @return array{sent:int,skipped:int,reason?:string}
     */
    public function custom(User $user, string $title, string $body, string $template, array $extraData = []): array
    {
        if ($this->inQuietHours()) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'quiet_hours'];
        }
        Log::info('[push] custom template dispatch', ['template' => $template, 'user_id' => $user->id]);

        return $this->push->send($user, $title, $body, ['template' => $template] + $extraData);
    }
}
