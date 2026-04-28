<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by ConversionEventPublisher::publish() right after a conversion event
 * is dispatched to the queue. Internal listeners (e.g. RecordFranchiseLead)
 * can react synchronously without waiting on the py-service round-trip.
 *
 * 為什麼新增 Laravel event 而不是直接在 publish() 裡寫 lead-recording：
 *   - publish() 已經有兩個職責（dispatch HTTP job + cache-bust lifecycle）；
 *     再塞 lead-recording 違反單一職責，且測試耦合高
 *   - Laravel event 讓「lead-recording」可獨立 unit test
 *   - 未來其他 reactor（例如 BD Discord 通知、analytics tagging）可掛同一個 event
 *
 * 為什麼不放 ShouldDispatchAfterCommit：
 *   - publisher 不在 transaction 內被呼叫的場景占多數；afterCommit 在這裡反而會
 *     讓 event 在 noop transaction 之外延遲到 next-tick，listener 拿不到。
 *   - lead 寫入是 idempotent（unique key），over-fire 的代價可接受。
 */
class ConversionEventPublished
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $pandoraUserUuid,
        public readonly string $eventType,
        public readonly array $payload,
    ) {}
}
