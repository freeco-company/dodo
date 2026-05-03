<?php

namespace App\Services\Insight;

use App\Models\Insight;
use App\Models\InsightRuleRun;
use App\Models\User;
use App\Services\Insight\Rules\InsightRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — main entry.
 *
 * For each registered rule:
 *   1. Skip if cooldown still active (idempotent via unique(user_id, rule_key, fired week))
 *   2. Build snapshot once
 *   3. Evaluate rule
 *   4. If triggered → write Insight + log InsightRuleRun(triggered=true)
 *      else → log InsightRuleRun(triggered=false) for debug
 *
 * PR #1 returns the fired insights so callers (Console command in PR #2,
 * realtime dispatch from controllers in PR #2) can act on them. Push +
 * frontend chat surface come in PR #4.
 */
class InsightEngine
{
    public function __construct(
        private readonly UserDataAggregator $aggregator,
        private readonly RuleRegistry $registry,
    ) {}

    /** @return array<int, Insight> */
    public function evaluateAllForUser(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Asia/Taipei');
        $snapshot = $this->aggregator->snapshotFor($user, $now);
        $fired = [];

        foreach ($this->registry->all() as $rule) {
            if ($this->isInCooldown($user, $rule, $now)) {
                $this->logRuleRun($user, $rule, $now, false, ['skipped' => 'cooldown']);

                continue;
            }

            $result = $rule->evaluate($user, $snapshot);

            if ($result === null) {
                $this->logRuleRun($user, $rule, $now, false, null);

                continue;
            }

            $insight = $this->writeInsight($user, $rule, $result, $now);
            $this->logRuleRun($user, $rule, $now, true, $result->detectionPayload);
            $fired[] = $insight;
        }

        return $fired;
    }

    private function isInCooldown(User $user, InsightRule $rule, CarbonImmutable $now): bool
    {
        $cutoff = $now->subDays($rule->cooldownDays());

        return Insight::query()
            ->where('user_id', $user->id)
            ->where('insight_key', $rule->key())
            ->where('fired_at', '>=', $cutoff)
            ->exists();
    }

    private function writeInsight(User $user, InsightRule $rule, InsightResult $result, CarbonImmutable $now): Insight
    {
        $idempotencyKey = sprintf(
            '%d:%s:%s',
            $user->id,
            $rule->key(),
            // Bucket by ISO week so a same-week double-fire is impossible
            // even if the cooldown calc above somehow disagrees.
            $now->isoFormat('GGGG-WW'),
        );

        return DB::transaction(function () use ($user, $rule, $result, $now, $idempotencyKey) {
            return Insight::firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'user_id' => $user->id,
                    'insight_key' => $rule->key(),
                    'detection_payload' => $result->detectionPayload,
                    'narrative_headline' => $result->headline,
                    'narrative_body' => $result->body,
                    'action_suggestion' => $result->actionSuggestions,
                    'source' => Insight::SOURCE_RULE_ENGINE,
                    'fired_at' => $now,
                ]
            );
        });
    }

    private function logRuleRun(User $user, InsightRule $rule, CarbonImmutable $now, bool $triggered, ?array $context): void
    {
        // Date column comparison must be on a normalized Y-m-d string, otherwise
        // SQLite treats `2026-05-04` and `2026-05-04 00:00:00` as different keys
        // and the unique(user_id, rule_key, eval_date) index throws on second eval.
        $evalDate = $now->toDateString();
        $existing = InsightRuleRun::query()
            ->where('user_id', $user->id)
            ->where('rule_key', $rule->key())
            ->whereDate('eval_date', $evalDate)
            ->first();

        if ($existing !== null) {
            $existing->update(['triggered' => $triggered, 'eval_context' => $context]);

            return;
        }
        InsightRuleRun::create([
            'user_id' => $user->id,
            'rule_key' => $rule->key(),
            'eval_date' => $evalDate,
            'triggered' => $triggered,
            'eval_context' => $context,
        ]);
    }
}
