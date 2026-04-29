<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot migration command — pushes existing pandora-meal users' `xp` totals into
 * the py-service gamification ledger as `migration.bootstrap` entries so
 * future events accumulate from the correct baseline. ADR-009 Phase B.
 *
 * Run AFTER py-service is deployed (alembic 0002+ + seed endpoints) and
 * pandora-meal's PANDORA_GAMIFICATION_BASE_URL/SECRET are set.
 *
 * Idempotent — re-running is a no-op (py-service skips users it already
 * bootstrapped). Safe to run on staging first then prod.
 *
 * Conservative defaults:
 *   - Batches of 100 users per HTTP call (py-service caps 1000; we go small
 *     so failures lose less progress).
 *   - Skips users with NULL pandora_user_uuid (not yet identity-linked).
 *   - Skips users with xp == 0 if --skip-zero (default true; LV.1 with 0 XP
 *     gives no value; the ledger row is wasteful audit noise).
 *   - --dry-run lists planned actions without POSTing.
 *
 * @see py-service /api/v1/internal/gamification/migration/bootstrap-ledger
 */
class GamificationBootstrapLedger extends Command
{
    protected $signature = 'gamification:bootstrap-ledger
        {--dry-run : Show what would be sent without calling py-service}
        {--include-zero : Also send users with xp=0 (default skips them)}
        {--batch=100 : Users per HTTP request to py-service (max 1000)}
        {--limit= : Optional cap on total users to process (testing)}';

    protected $description = 'Phase B migration: push existing users\' xp into py-service ledger as migration.bootstrap';

    public function handle(): int
    {
        $base = rtrim((string) config('services.pandora_gamification.base_url'), '/');
        $secret = (string) config('services.pandora_gamification.shared_secret');
        if ($base === '' || $secret === '') {
            $this->error('PANDORA_GAMIFICATION_BASE_URL / SHARED_SECRET not configured. Set both before running.');

            return self::FAILURE;
        }
        $batchSize = max(1, min((int) $this->option('batch'), 1000));
        $dryRun = (bool) $this->option('dry-run');
        $includeZero = (bool) $this->option('include-zero');
        $limit = $this->option('limit');
        $limit = $limit !== null ? (int) $limit : null;

        $url = $base.'/api/v1/internal/gamification/migration/bootstrap-ledger';

        $query = User::query()
            ->whereNotNull('pandora_user_uuid');
        if (! $includeZero) {
            $query->where('xp', '>', 0);
        }
        $query->orderBy('id');

        $totalCandidates = (clone $query)->count();
        if ($limit !== null) {
            $totalCandidates = min($totalCandidates, $limit);
        }
        $this->info(sprintf(
            '[bootstrap] %d candidate users (dry_run=%s, include_zero=%s, batch=%d)',
            $totalCandidates,
            $dryRun ? 'yes' : 'no',
            $includeZero ? 'yes' : 'no',
            $batchSize,
        ));

        if ($totalCandidates === 0) {
            $this->info('Nothing to bootstrap. Done.');

            return self::SUCCESS;
        }

        $totalSent = 0;
        $totalNew = 0;
        $totalSkipped = 0;
        $batchEntries = [];

        $iterator = $query->cursor();
        $processed = 0;
        foreach ($iterator as $user) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            $processed++;
            $batchEntries[] = [
                'pandora_user_uuid' => (string) $user->pandora_user_uuid,
                'total_xp' => (int) ($user->xp ?? 0),
                'source_app' => 'meal',
            ];
            if (count($batchEntries) >= $batchSize) {
                [$new, $skipped] = $this->flushBatch($url, $secret, $batchEntries, $dryRun);
                $totalSent += count($batchEntries);
                $totalNew += $new;
                $totalSkipped += $skipped;
                $batchEntries = [];
            }
        }
        if (count($batchEntries) > 0) {
            [$new, $skipped] = $this->flushBatch($url, $secret, $batchEntries, $dryRun);
            $totalSent += count($batchEntries);
            $totalNew += $new;
            $totalSkipped += $skipped;
        }

        $this->info(sprintf(
            '[bootstrap] done. sent=%d new=%d skipped=%d',
            $totalSent,
            $totalNew,
            $totalSkipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{0:int, 1:int}  [new_count, skipped_count]
     */
    private function flushBatch(string $url, string $secret, array $entries, bool $dryRun): array
    {
        if ($dryRun) {
            $this->line(sprintf('[bootstrap] (dry-run) would POST batch of %d entries', count($entries)));

            return [0, 0];
        }

        $response = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->post($url, ['entries' => $entries]);

        if (! $response->successful()) {
            $this->error(sprintf(
                '[bootstrap] HTTP %d from py-service: %s',
                $response->status(),
                substr((string) $response->body(), 0, 200),
            ));

            // Don't continue blindly on a server error — abort so ops can investigate.
            throw new \RuntimeException('bootstrap-ledger failed; check py-service logs');
        }

        $body = $response->json();
        $new = (int) ($body['new_bootstraps'] ?? 0);
        $skipped = (int) ($body['skipped'] ?? 0);
        $this->line(sprintf(
            '[bootstrap] batch ok: new=%d skipped=%d total=%d',
            $new,
            $skipped,
            count($entries),
        ));

        return [$new, $skipped];
    }
}
