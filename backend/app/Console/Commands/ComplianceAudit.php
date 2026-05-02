<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use Illuminate\Console\Command;
use Pandora\Shared\Compliance\LegalContentSanitizer;

/**
 * 集團合規硬規則（docs/group-fp-product-compliance.md）— pandora-meal 端的稽核。
 *
 * 掃 KnowledgeArticle.body / dodo_voice_body / summary / title 找違規詞，
 * 預設 dry-run（report only），加 --apply 才實際 auto-rewrite + 補 disclaimer。
 *
 * 跑頻率：每天（routes/console.php Schedule）。
 */
class ComplianceAudit extends Command
{
    protected $signature = 'compliance:audit
        {--apply : Mutate rows (default is dry-run, report only)}';

    protected $description = '掃 pandora-meal KB articles 找違規詞，dry-run 報告或 --apply 自動修正';

    public function __construct(
        private readonly LegalContentSanitizer $sanitizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? '=== Compliance audit (apply) ===' : '=== Compliance audit (dry-run) ===');

        $articleStats = $this->auditArticles($apply);
        $deckStats = $this->auditQuestionDeck();

        $this->table(
            ['Kind', 'Scanned', 'Flagged', 'Fixed', 'Top Terms'],
            [
                ['knowledge_article', $articleStats['scanned'], $articleStats['flagged'], $articleStats['fixed'], $this->topTerms($articleStats['terms'])],
                ['question_deck', $deckStats['scanned'], $deckStats['flagged'], 0, $this->topTerms($deckStats['terms'])],
            ],
        );

        // Question deck violations are CI-fatal (read-only file, not auto-fixable).
        if ($deckStats['flagged'] > 0) {
            $this->error("question_decks.json has {$deckStats['flagged']} card(s) with violation terms — fix the seed file by hand.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * SPEC-06 Phase 2 — also scan the seasonal / holiday / lore card seed
     * for compliance terms. These cards ship in code (not DB-driven) so a
     * regression at PR-time should fail loudly, not auto-rewrite.
     *
     * @return array{scanned:int,flagged:int,terms:array<string,int>}
     */
    private function auditQuestionDeck(): array
    {
        $path = database_path('seed/question_decks.json');
        if (! file_exists($path)) {
            return ['scanned' => 0, 'flagged' => 0, 'terms' => []];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['scanned' => 0, 'flagged' => 0, 'terms' => []];
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['cards']) || ! is_array($data['cards'])) {
            return ['scanned' => 0, 'flagged' => 0, 'terms' => []];
        }

        $scanned = 0;
        $flagged = 0;
        $terms = [];
        foreach ($data['cards'] as $card) {
            if (! is_array($card)) {
                continue;
            }
            $scanned++;
            $hits = [];
            $textFields = [
                (string) ($card['question'] ?? ''),
                (string) ($card['hint'] ?? ''),
                (string) ($card['explain'] ?? ''),
            ];
            foreach ((array) ($card['choices'] ?? []) as $choice) {
                if (! is_array($choice)) {
                    continue;
                }
                $textFields[] = (string) ($choice['text'] ?? '');
                $textFields[] = (string) ($choice['feedback'] ?? '');
            }
            foreach ($textFields as $val) {
                foreach ($this->sanitizer->riskReport($val) as $term) {
                    $hits[] = $term;
                }
            }
            $hits = array_values(array_unique($hits));
            if ($hits === []) {
                continue;
            }
            $flagged++;
            foreach ($hits as $t) {
                $terms[$t] = ($terms[$t] ?? 0) + 1;
            }
        }

        return compact('scanned', 'flagged', 'terms');
    }

    /**
     * @return array{scanned:int,flagged:int,fixed:int,terms:array<string,int>}
     */
    private function auditArticles(bool $apply): array
    {
        $scanned = 0;
        $flagged = 0;
        $fixed = 0;
        $terms = [];

        foreach (KnowledgeArticle::query()->cursor() as $row) {
            $scanned++;

            // 4 個 user-facing 欄位都要掃
            $payload = [
                'title' => $row->title ?? '',
                'summary' => $row->summary ?? '',
                'body' => $row->body ?? '',
                'dodo_voice_body' => $row->dodo_voice_body ?? '',
            ];

            $hits = [];
            foreach ($payload as $field => $val) {
                foreach ($this->sanitizer->riskReport((string) $val) as $term) {
                    $hits[] = $term;
                }
            }
            $hits = array_values(array_unique($hits));

            if (empty($hits)) {
                continue;
            }

            $flagged++;
            foreach ($hits as $t) {
                $terms[$t] = ($terms[$t] ?? 0) + 1;
            }

            if ($apply) {
                $row->title = $this->sanitizer->sanitizeText((string) $row->title);
                $row->summary = $this->sanitizer->sanitizeText((string) $row->summary);
                // body / dodo_voice_body 是純文字（KB seeders 寫的），不是 HTML
                $row->body = $this->sanitizer->sanitizeText((string) $row->body);
                $row->dodo_voice_body = $this->sanitizer->sanitizeText((string) $row->dodo_voice_body);
                $row->save();
                $fixed++;
            }
        }

        return compact('scanned', 'flagged', 'fixed', 'terms');
    }

    /**
     * @param  array<string,int>  $terms
     */
    private function topTerms(array $terms): string
    {
        if (empty($terms)) {
            return '—';
        }
        arsort($terms);
        $top = array_slice($terms, 0, 5, true);
        $out = [];
        foreach ($top as $t => $n) {
            $out[] = "{$t}:{$n}";
        }

        return implode(', ', $out);
    }
}
