<?php

namespace App\Services\Ai;

use App\Models\UsageLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Per-user monthly Anthropic spend tracker + circuit breaker.
 *
 * Goal: enforce the NT$80/MAU guard rail (project CLAUDE.md) before a runaway
 * prompt or abusive user blows up the unit economics. Real Anthropic invoices
 * arrive monthly; this service is the realtime mirror.
 *
 * Pricing rates live in `config/services.ai_cost_guard.pricing` keyed by
 * model id. The pricing table is opinionated, not generic — adding a new
 * Anthropic model = adding a row.
 *
 * Wire points (call sites):
 *   - {@see \App\Http\Controllers\Api\ChatController}: `assertWithinBudget()`
 *     before dispatching a request, then `record()` after the response.
 *   - Food recognition: same pattern.
 *
 * If `services.ai_cost_guard.enabled = false` the service still records (so
 * we collect data) but never throws — useful for first-week observation
 * before flipping enforcement on.
 */
class AiCostGuardService
{
    /**
     * Record a finished AI call's token usage. Idempotency is the caller's
     * responsibility (one row per request).
     */
    public function record(
        User $user,
        string $kind,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?int $latencyMs = null,
    ): UsageLog {
        return UsageLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            // Asia/Taipei to match the month-boundary computation in
            // monthlyCostUsd(). Using the app default (UTC) caused rows
            // written in the 8h window before UTC midnight to fall outside
            // the Taipei month range and silently undercount spend.
            'date' => Carbon::now('Asia/Taipei')->toDateString(),
            'kind' => $kind,
            'model' => $model,
            'tokens' => $inputTokens + $outputTokens,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
        ]);
    }

    /**
     * Sum a user's spend in USD for the current calendar month (UTC+8).
     * Uses input/output split when available; falls back to total tokens at
     * blended pricing for legacy rows.
     */
    public function monthlyCostUsd(User $user, ?Carbon $asOf = null): float
    {
        $asOf ??= Carbon::now('Asia/Taipei');
        $start = $asOf->copy()->startOfMonth()->toDateString();
        $end = $asOf->copy()->endOfMonth()->toDateString();

        $rows = UsageLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->get(['model', 'tokens', 'input_tokens', 'output_tokens']);

        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->rowCostUsd($row);
        }

        return $total;
    }

    /**
     * Throws \RuntimeException with a stable code if the user is at/over the
     * monthly cap. Caller can catch + return 429.
     */
    public function assertWithinBudget(User $user): void
    {
        if (! $this->enabled()) {
            return;
        }
        $spent = $this->monthlyCostUsd($user);
        $cap = $this->cap();
        if ($spent >= $cap) {
            Log::info('[AiCostGuard] user over monthly cap', [
                'user_id' => $user->id,
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'spent_usd' => round($spent, 4),
                'cap_usd' => $cap,
            ]);
            throw new AiCostCapExceeded($spent, $cap);
        }
    }

    public function isOverCap(User $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->monthlyCostUsd($user) >= $this->cap();
    }

    /** @return array{spent_usd: float, cap_usd: float, fraction: float, over: bool} */
    public function snapshot(User $user): array
    {
        $spent = $this->monthlyCostUsd($user);
        $cap = $this->cap();

        return [
            'spent_usd' => round($spent, 4),
            'cap_usd' => $cap,
            'fraction' => $cap > 0 ? round($spent / $cap, 4) : 0.0,
            'over' => $this->enabled() && $spent >= $cap,
        ];
    }

    private function rowCostUsd(UsageLog $row): float
    {
        $model = (string) ($row->model ?? 'default');
        $rates = $this->rates($model);
        $input = (int) ($row->input_tokens ?? 0);
        $output = (int) ($row->output_tokens ?? 0);
        if ($input === 0 && $output === 0) {
            // Legacy row — blend total tokens 50/50
            $total = (int) ($row->tokens ?? 0);
            $input = intdiv($total, 2);
            $output = $total - $input;
        }

        return ($input / 1_000_000) * $rates['input']
            + ($output / 1_000_000) * $rates['output'];
    }

    /** @return array{input: float, output: float} */
    private function rates(string $model): array
    {
        $pricing = (array) config('services.ai_cost_guard.pricing', []);
        $rates = $pricing[$model] ?? $pricing['default'] ?? ['input' => 5.0, 'output' => 25.0];

        return [
            'input' => (float) ($rates['input'] ?? 5.0),
            'output' => (float) ($rates['output'] ?? 25.0),
        ];
    }

    private function cap(): float
    {
        return (float) config('services.ai_cost_guard.monthly_cap_usd', 2.50);
    }

    private function enabled(): bool
    {
        return (bool) config('services.ai_cost_guard.enabled', true);
    }
}
