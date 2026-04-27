<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Translated (slimmed) from ai-game/src/services/seo.ts.
 *
 * Per-path SEO meta overrides + sitemap generator.
 * Admin manages via PUT /api/admin/seo; public consumer is /sitemap.xml.
 */
class SeoService
{
    public function list(): array
    {
        return DB::table('seo_metas')->orderBy('path')->get()->all();
    }

    public function get(string $path): ?object
    {
        return DB::table('seo_metas')->where('path', $path)->first();
    }

    /** @param array{path:string, title?:?string, description?:?string, og_image?:?string, locale?:?string} $input */
    public function upsert(array $input): object
    {
        DB::table('seo_metas')->upsert(
            [[
                'path' => $input['path'],
                'title' => $input['title'] ?? null,
                'description' => $input['description'] ?? null,
                'og_image' => $input['og_image'] ?? null,
                'locale' => $input['locale'] ?? 'zh-TW',
                'updated_at' => now(),
            ]],
            ['path'],
            ['title', 'description', 'og_image', 'locale', 'updated_at']
        );
        return $this->get($input['path']);
    }

    public function delete(string $path): bool
    {
        return DB::table('seo_metas')->where('path', $path)->delete() > 0;
    }

    /** Build a minimal sitemap.xml from registered seo_metas paths. */
    public function sitemapXml(string $baseUrl = 'https://doudou.freeco.tw'): string
    {
        $rows = DB::table('seo_metas')->orderBy('path')->get(['path', 'updated_at']);
        $urls = '';
        foreach ($rows as $r) {
            $loc = htmlspecialchars(rtrim($baseUrl, '/') . '/' . ltrim($r->path, '/'), ENT_XML1);
            $lastmod = (string) $r->updated_at;
            $urls .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n  </url>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls
            . '</urlset>' . "\n";
    }
}
