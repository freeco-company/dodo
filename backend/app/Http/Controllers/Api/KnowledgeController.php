<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleUserMark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/knowledge/* — App-side knowledge feed endpoints.
 *
 * v1: list + daily-pick + show + mark.
 *
 * Audience filter: defaults to 'retail' (一般 App 用戶). For future 加盟者 App
 * /admin views, pass `?audience=franchisee`.
 */
class KnowledgeController extends Controller
{
    /**
     * GET /api/knowledge?category=protein&audience=retail
     *
     * Listing of published articles, optionally filtered. Returns dodo_voice_body
     * if available, otherwise body. Lists most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->string('category')->toString();
        $audience = $request->string('audience', 'retail')->toString();

        $query = KnowledgeArticle::query()->published();
        if ($category !== '') {
            $query->where('category', $category);
        }
        if ($audience !== '') {
            $query->forAudience($audience);
        }

        $rows = $query->orderByDesc('published_at')->limit(50)->get();

        return response()->json([
            'articles' => $rows->map(fn ($r) => $this->shape($r))->all(),
            'count' => $rows->count(),
        ]);
    }

    /**
     * GET /api/knowledge/daily — pick one article for "今日營養知識".
     *
     * Deterministic per-day per-user: uses crc32(date . uuid) mod count to
     * pick a stable article for the day so refreshing stays consistent.
     */
    public function daily(Request $request): JsonResponse
    {
        $audience = $request->string('audience', 'retail')->toString();
        $count = KnowledgeArticle::query()->published()->forAudience($audience)->count();
        if ($count === 0) {
            return response()->json(['article' => null, 'reason' => 'empty_kb']);
        }
        $seed = crc32(now()->toDateString() . ($request->user()->pandora_user_uuid ?? ''));
        $offset = $seed % $count;

        $article = KnowledgeArticle::query()
            ->published()
            ->forAudience($audience)
            ->orderBy('id')
            ->skip($offset)
            ->first();

        if ($article === null) {
            return response()->json(['article' => null, 'reason' => 'empty_kb']);
        }

        return response()->json(['article' => $this->shape($article)]);
    }

    /**
     * GET /api/knowledge/{slug} — full article.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $article = KnowledgeArticle::query()
            ->where('slug', $slug)
            ->published()
            ->first();
        if (! $article) {
            return response()->json(['message' => 'not found'], 404);
        }
        // Track view (fire-and-forget — don't block response on a write)
        $article->increment('view_count');
        KnowledgeArticleUserMark::create([
            'article_id' => $article->id,
            'pandora_user_uuid' => $request->user()->pandora_user_uuid ?? '',
            'action' => 'viewed',
            'acted_at' => now(),
        ]);

        return response()->json(['article' => $this->shape($article, full: true)]);
    }

    /**
     * POST /api/knowledge/{slug}/save — bookmark for later.
     */
    public function save(Request $request, string $slug): JsonResponse
    {
        $article = KnowledgeArticle::query()->where('slug', $slug)->published()->first();
        if (! $article) {
            return response()->json(['message' => 'not found'], 404);
        }
        $article->increment('saved_count');
        KnowledgeArticleUserMark::create([
            'article_id' => $article->id,
            'pandora_user_uuid' => $request->user()->pandora_user_uuid ?? '',
            'action' => 'saved',
            'acted_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(KnowledgeArticle $a, bool $full = false): array
    {
        return [
            'slug' => $a->slug,
            'title' => $a->title,
            'category' => $a->category,
            'tags' => $a->tags ?? [],
            'audience' => $a->audience ?? [],
            'summary' => $a->summary,
            'body' => $full ? ($a->dodo_voice_body ?: $a->body) : null,
            'reading_time_seconds' => $a->reading_time_seconds,
            'published_at' => $a->published_at?->toIso8601String(),
            'view_count' => $a->view_count,
        ];
    }
}
