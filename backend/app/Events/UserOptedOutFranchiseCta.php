<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a user explicitly opts out of franchise CTA via
 * POST /api/me/franchise-cta-silence (silenced=true).
 *
 * Listener: SilenceFranchiseLeads — marks any open lead row as 'silenced'
 * so the BD inbox immediately reflects the user's wish to be left alone.
 *
 * 設計注意：
 *   - opt-out 是 strong signal，比任何 funnel score 重要；任何 BD 流程
 *     都應該 honor 這個 flag。
 *   - 廣播為 Laravel event 而非直接更新 DB：未來可能還想在 admin Discord
 *     貼一條「user X 主動 opt-out」的 audit trail。
 */
class UserOptedOutFranchiseCta
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $pandoraUserUuid,
        public readonly bool $silenced,
    ) {}
}
