<?php

namespace App\Console\Commands;

use App\Models\KnowledgeArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * php artisan kb:ocr-import [--limit=N] [--start=N] [--publish] [--model=claude-sonnet-4-6]
 *
 * Phase 5c — 把 storage/seed/nutrition_kb/raw/ 的 160 張營養師群組截圖批次
 * 跑過 Anthropic vision API → 結構化 → 寫進 knowledge_articles。
 *
 * 用 Claude Sonnet 4.6 vision，估計：
 *   - input image ~1500 tok + prompt ~400 tok = $0.0057 / 張
 *   - output ~600 tok = $0.009 / 張
 *   - per image: ~$0.015
 *   - 160 張: ~$2.4 USD（user 2026-04-30 授權 spend）
 *
 * Default 不 publish — 先寫成 draft（published_at = null），admin 在
 * /admin/knowledge-articles review + 必要修改 + publish。
 *
 * 用 --publish 直接 published_at = now()（敢用就是 trust 朵朵語氣改寫品質）。
 *
 * 重複處理保護：source_image 為 unique-ish key，已存在的會 skip。
 */
class KbOcrImport extends Command
{
    protected $signature = 'kb:ocr-import
        {--limit=10 : Max images to process this run}
        {--start=0 : Skip first N raw files (for resuming after crash)}
        {--publish : Mark articles as published immediately (default: draft)}
        {--model=claude-sonnet-4-6 : Anthropic model id}
        {--dry-run : Print payloads, don\'t call API or write DB}';

    protected $description = 'Batch-OCR raw nutrition images via Claude vision → seed knowledge_articles (drafts).';

    public function handle(): int
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey && ! $this->option('dry-run')) {
            $this->error('ANTHROPIC_API_KEY not set in .env');

            return self::FAILURE;
        }

        $rawDir = storage_path('seed/nutrition_kb/raw');
        if (! is_dir($rawDir)) {
            $this->error("Raw dir not found: $rawDir");

            return self::FAILURE;
        }

        $files = collect(glob($rawDir.'/*.{jpg,jpeg,png}', GLOB_BRACE))
            ->sort()
            ->values();

        $start = (int) $this->option('start');
        $limit = (int) $this->option('limit');
        $batch = $files->slice($start, $limit);

        $this->info("Total raw files: {$files->count()}");
        $this->info('Processing batch: '.$batch->count()." (start={$start}, limit={$limit})");
        $this->info('Model: '.$this->option('model'));
        $this->info('Mode: '.($this->option('dry-run') ? 'DRY-RUN' : 'LIVE'));
        $this->info('Publish: '.($this->option('publish') ? 'YES' : 'NO (draft)'));
        $this->newLine();

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($batch as $i => $path) {
            $filename = basename($path);
            $idx = $start + $i;
            $this->line(sprintf('[%d/%d] %s', $idx + 1, $files->count(), $filename));

            // Skip if already processed
            $existing = KnowledgeArticle::where('source_image', $filename)->first();
            if ($existing) {
                $this->warn("  → already imported as id={$existing->id} ({$existing->slug}), skip");
                $skipped++;

                continue;
            }

            try {
                if ($this->option('dry-run')) {
                    $this->line("  → DRY-RUN, would call API + insert draft");
                    $imported++;

                    continue;
                }

                $extracted = $this->extractFromImage($path, $apiKey, $this->option('model'));
                if (! $extracted) {
                    $this->error('  → extraction failed (empty)');
                    $failed++;

                    continue;
                }

                // Generate unique slug
                $slug = Str::slug($extracted['slug_base'] ?? $extracted['title']);
                $slug = $this->ensureUniqueSlug($slug, $filename);

                $article = KnowledgeArticle::create([
                    'slug' => $slug,
                    'title' => mb_substr($extracted['title'], 0, 200),
                    'category' => $extracted['category'] ?? 'other',
                    'tags' => $extracted['tags'] ?? [],
                    'audience' => $extracted['audience'] ?? ['retail'],
                    'summary' => $extracted['summary'] ?? null,
                    'body' => $extracted['body'] ?? '',
                    'dodo_voice_body' => $extracted['dodo_voice_body'] ?? null,
                    'reading_time_seconds' => $extracted['reading_time_seconds'] ?? 60,
                    'source_image' => $filename,
                    'source_attribution' => '專業營養師群組分享 (OCR import 2026-04-30)',
                    'published_at' => $this->option('publish') ? now() : null,
                ]);

                $this->info("  ✓ created id={$article->id} slug={$article->slug} cat={$article->category}");
                $imported++;
            } catch (\Throwable $e) {
                $this->error('  → error: '.$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->line("Summary: imported={$imported}, skipped={$skipped}, failed={$failed}");
        $this->line('Next batch hint: --start='.($start + $limit));

        return self::SUCCESS;
    }

    /**
     * Call Anthropic vision API with the extraction prompt.
     *
     * @return array<string, mixed>|null
     */
    private function extractFromImage(string $path, string $apiKey, string $model): ?array
    {
        $imageData = base64_encode(file_get_contents($path));
        $mediaType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        $systemPrompt = <<<'PROMPT'
You are a nutrition content extractor for a Taiwan health-coaching app called 「潘朵拉飲食」.
The user uploads a Chinese-text image (often a screenshot of a nutritionist Q&A from a LINE group).

Extract structured data and ALSO rewrite the body in 朵朵 NPC voice.

朵朵 voice rules (HARD):
- Use 妳 (not 您) when addressing user
- Address as 朋友 / 朵朵's friend (never 會員 / 用戶 / customer)
- Warm, conversational, no jargon dump
- Short paragraphs, can use bullet lists with • or emoji bullets
- 1-2 emojis max per article 點綴用 (no emoji blast)
- Never use marketing pushy words like 立刻 / 快速 / 升級加盟方案
- Sign off optional, no signature spam

Output STRICT JSON only — no markdown fence, no commentary. Keys:
  title (string, ≤30 char Chinese)
  slug_base (lowercase ascii, hyphenated, 3-6 words, max 50 char)
  category (one of: protein, carb, fiber, fat, water, micronutrient, product_match, meal_timing, cutting, maintenance, qna, myth_busting, lifestyle, other)
  tags (array of 2-4 short Chinese tags like ["減脂期", "便利商店"])
  audience (subset of ["retail", "franchisee"], default ["retail"])
  summary (string, one sentence, ≤80 char)
  body (string, original nutritionist text faithfully transcribed, can be 200-1000 char)
  dodo_voice_body (string, 朵朵 voice rewrite of body, can use \n for paragraphs)
  reading_time_seconds (integer, 30-180)

If the image is NOT a nutrition knowledge piece (e.g., a meme, advertisement, blank, garbled), return:
  {"unrelated": true, "reason": "<short reason>"}
PROMPT;

        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $imageData,
                        ]],
                        ['type' => 'text', 'text' => 'Extract structured JSON from this nutrition knowledge image.'],
                    ],
                ],
            ],
        ];

        $resp = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', $payload);

        if ($resp->failed()) {
            $this->error('  → API '.$resp->status().': '.mb_substr($resp->body(), 0, 200));

            return null;
        }

        $data = $resp->json();
        $text = $data['content'][0]['text'] ?? '';
        if ($text === '') {
            return null;
        }

        // Strip markdown code fences if model wraps JSON
        $text = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($text));

        try {
            $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->warn('  → JSON parse failed: '.mb_substr($text, 0, 100));

            return null;
        }

        if (! empty($parsed['unrelated'])) {
            $this->warn('  → flagged unrelated: '.($parsed['reason'] ?? '?'));

            return null;
        }

        return $parsed;
    }

    private function ensureUniqueSlug(string $base, string $filename): string
    {
        $base = $base ?: 'kb-'.substr(md5($filename), 0, 8);
        $slug = $base;
        $i = 2;
        while (KnowledgeArticle::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
            if ($i > 50) {
                $slug = $base.'-'.substr(md5($filename), 0, 6);
                break;
            }
        }

        return $slug;
    }
}
