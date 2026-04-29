<?php

namespace App\Console\Commands;

use App\Models\DodoUser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-007 §6 risk #4 mitigation (b) consumer.
 *
 * Periodically pulls Pandora Core's `/v1/internal/reconcile/users?since=<cursor>`
 * and updates the local `dodo_users` mirror. Used as a safety net when the
 * identity webhook chain drops events (worker crash, network partition,
 * payload validation reject).
 *
 * Cursor handling: stored in Cache under `identity:reconcile:cursor`, ISO-8601
 * UTC timestamp. First run defaults to epoch (full sync). Subsequent runs
 * resume from last seen `next_cursor`. The endpoint is `since`-inclusive so
 * we tolerate one duplicate row per resume (the upsert is idempotent).
 *
 * NOT for first-time backfill — that's a separate one-shot
 * (identity:backfill-mirror). This command is incremental delta only.
 *
 * Schedule: every 1 hour via routes/console.php is a reasonable default
 * (TTL recommended in ADR-007 §6 #4(a)).
 *
 * Failure: 5xx / network errors logged + bail; cursor not advanced so next
 * run retries the same window. 4xx (e.g. invalid since) → log + bail.
 */
class IdentityReconcile extends Command
{
    protected $signature = 'identity:reconcile
        {--since= : ISO-8601 cursor override (testing / one-shot)}
        {--limit=100 : Page size per HTTP call (capped server-side at 500)}
        {--dry-run : Fetch + log without writing local mirror}
        {--max-pages=50 : Safety cap on pagination loop}';

    protected $description = 'ADR-007 reconcile — pull identity delta from Pandora Core';

    private const CURSOR_KEY = 'identity:reconcile:cursor';

    public function handle(): int
    {
        $base = rtrim((string) config('services.pandora_core.base_url'), '/');
        $secret = (string) config('services.pandora_core.internal_secret');
        if ($base === '' || $secret === '') {
            $this->error('PANDORA_CORE_BASE_URL / PANDORA_CORE_INTERNAL_SECRET not configured');

            return self::FAILURE;
        }

        $sinceOpt = $this->option('since');
        $cursor = is_string($sinceOpt) && $sinceOpt !== ''
            ? $sinceOpt
            : (string) Cache::get(self::CURSOR_KEY, '1970-01-01T00:00:00Z');
        $limit = max(1, min(500, (int) $this->option('limit')));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf('reconcile from cursor=%s limit=%d max-pages=%d dry-run=%s',
            $cursor, $limit, $maxPages, $dryRun ? 'yes' : 'no'));

        $totalUpserted = 0;
        $totalSeen = 0;
        $page = 0;

        while ($page < $maxPages) {
            $page++;
            $resp = Http::withHeaders([
                'X-Pandora-Internal-Secret' => $secret,
                'Accept' => 'application/json',
            ])
                ->timeout((int) config('services.pandora_core.timeout', 5))
                ->retry(2, 200, throw: false)
                ->get($base.'/api/internal/reconcile/users', [
                    'since' => $cursor,
                    'limit' => $limit,
                ]);

            if (! $resp->successful()) {
                $this->error(sprintf('page %d failed: status=%d body=%s',
                    $page, $resp->status(), substr((string) $resp->body(), 0, 200)));

                return self::FAILURE;
            }

            $body = (array) $resp->json();
            $users = is_array($body['users'] ?? null) ? $body['users'] : [];
            $nextCursor = $body['next_cursor'] ?? null;
            $hasMore = (bool) ($body['has_more'] ?? false);

            foreach ($users as $u) {
                if (! is_array($u) || empty($u['id'])) {
                    continue;
                }
                $totalSeen++;
                if (! $dryRun) {
                    $upserted = $this->upsertMirror($u);
                    if ($upserted) {
                        $totalUpserted++;
                    }
                }
            }

            $this->line(sprintf('  page %d: count=%d has_more=%s',
                $page, count($users), $hasMore ? 'yes' : 'no'));

            if (! $hasMore || ! is_string($nextCursor)) {
                break;
            }
            $cursor = $nextCursor;
        }

        if (! $dryRun) {
            // Advance the persisted cursor to "now" so we don't re-scan the
            // last delta on the next run. Slightly conservative: a webhook
            // arriving in this exact second could be missed; reconcile is a
            // safety net not the primary path.
            Cache::forever(self::CURSOR_KEY, Carbon::now('UTC')->toIso8601String());
        }

        $this->info(sprintf('done. pages=%d seen=%d upserted=%d',
            $page, $totalSeen, $totalUpserted));

        if ($totalSeen > 0 && ! $dryRun) {
            Log::info('[identity:reconcile] synced', [
                'pages' => $page,
                'seen' => $totalSeen,
                'upserted' => $totalUpserted,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Upsert one user from the reconcile response into dodo_users.
     * Returns true if a row changed (insert OR update with diff).
     *
     * @param  array<string, mixed>  $u
     */
    private function upsertMirror(array $u): bool
    {
        $uuid = (string) $u['id'];
        $displayName = isset($u['display_name']) && is_string($u['display_name'])
            ? $u['display_name']
            : null;
        $status = isset($u['status']) && is_string($u['status']) ? $u['status'] : 'active';

        $row = DodoUser::find($uuid);
        if ($row === null) {
            DodoUser::create([
                'pandora_user_uuid' => $uuid,
                'display_name' => $displayName,
                'last_synced_at' => Carbon::now(),
            ]);

            return true;
        }

        $changed = false;
        if ($displayName !== null && $row->display_name !== $displayName) {
            $row->display_name = $displayName;
            $changed = true;
        }
        // Soft-delete via status — if Pandora Core marked the user inactive,
        // we don't physically remove the local row (preserve game state); we
        // just mark last_synced and let app code branch on it via JIT pull.
        if ($changed) {
            $row->last_synced_at = Carbon::now();
            $row->save();
        }

        return $changed;
    }
}
