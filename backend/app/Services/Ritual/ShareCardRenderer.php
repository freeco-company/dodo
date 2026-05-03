<?php

namespace App\Services\Ritual;

use App\Models\ShareCardRender;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC-progress-ritual-v1 PR #1 + PR #7 — share card PNG renderer.
 *
 * PR #1 shipped a 1x1 transparent placeholder so the contract worked.
 * PR #7 implements actual GD-based composition: 1080x1920 PNG with peach
 * gradient background + headline + stats lines + footer hashtag.
 *
 * Compliance:
 *   - NEVER renders kg / weight numbers (caller passes only neutral stats)
 *   - Caller (RitualController) controls payload — renderer just paints
 *     whatever strings it gets
 *   - Face-blurred snapshot photos NOT included (snapshots are device-only
 *     per SPEC §4.1; share card stays text + stats only — also dodges
 *     any AI-on-photo concerns)
 *
 * Font strategy:
 *   - Try TrueType (config('ritual.share_card_font_path') or system fonts)
 *   - Fallback to GD's built-in pixel fonts (functional, less pretty)
 *   - Both paths render the SAME content + checksum, so cache stays valid
 *
 * @phpstan-type ShareCardContent array{
 *     headline?: string,
 *     subtitle?: string,
 *     stats?: array<int, array{label: string, value: string}>,
 *     footer?: string,
 *     accent_hex?: string,
 * }
 */
class ShareCardRenderer
{
    private const CARD_WIDTH = 1080;
    private const CARD_HEIGHT = 1920;
    private const PADDING = 80;

    public function render(User $user, string $sourceType, int $sourceId, array $content): ShareCardRender
    {
        $checksum = hash('sha256', $sourceType.':'.$sourceId.':'.json_encode($content));

        $existing = ShareCardRender::query()
            ->where('user_id', $user->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('checksum', $checksum)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $relPath = sprintf('share-cards/%d/%s_%d_%s.png', $user->id, $sourceType, $sourceId, substr($checksum, 0, 8));

        $png = $this->composeCard($user, $sourceType, $content);
        Storage::disk('local')->put($relPath, $png);

        return ShareCardRender::create([
            'user_id' => $user->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'image_path' => $relPath,
            'checksum' => $checksum,
        ]);
    }

    /** @param  array<string, mixed>  $content */
    private function composeCard(User $user, string $sourceType, array $content): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            // GD not installed — return placeholder so endpoint still works.
            Log::warning('[ShareCardRenderer] GD not loaded, returning placeholder');

            return $this->placeholderPng();
        }

        $img = imagecreatetruecolor(self::CARD_WIDTH, self::CARD_HEIGHT);

        // Peach gradient background (top to bottom).
        $top = imagecolorallocate($img, 0xFF, 0xE4, 0xD6);
        $bot = imagecolorallocate($img, 0xFF, 0xD1, 0xBD);
        for ($y = 0; $y < self::CARD_HEIGHT; $y++) {
            $t = $y / self::CARD_HEIGHT;
            $r = (int) (0xFF * (1 - $t) + 0xFF * $t);
            $g = (int) (0xE4 * (1 - $t) + 0xD1 * $t);
            $b = (int) (0xD6 * (1 - $t) + 0xBD * $t);
            $line = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, self::CARD_WIDTH, $y, $line);
        }

        $accent = $this->parseHex($content['accent_hex'] ?? '#D87850');
        $accentColor = imagecolorallocate($img, $accent[0], $accent[1], $accent[2]);
        $textColor = imagecolorallocate($img, 0x33, 0x33, 0x33);
        $mutedColor = imagecolorallocate($img, 0x88, 0x77, 0x66);

        $fontPath = $this->fontPath();
        $useTtf = $fontPath !== null;

        $y = 200;
        $headline = (string) ($content['headline'] ?? '🌱 朵朵');
        $this->drawText($img, $headline, self::PADDING, $y, 56, $accentColor, $useTtf, $fontPath);
        $y += 100;

        if (! empty($content['subtitle'])) {
            $this->drawText($img, (string) $content['subtitle'], self::PADDING, $y, 32, $mutedColor, $useTtf, $fontPath);
            $y += 80;
        }

        $stats = $content['stats'] ?? [];
        if (is_array($stats)) {
            foreach ($stats as $stat) {
                if (! is_array($stat)) {
                    continue;
                }
                $label = (string) ($stat['label'] ?? '');
                $value = (string) ($stat['value'] ?? '');
                $this->drawText($img, $label, self::PADDING, $y, 28, $mutedColor, $useTtf, $fontPath);
                $this->drawText($img, $value, self::PADDING, $y + 40, 56, $textColor, $useTtf, $fontPath);
                $y += 140;
            }
        }

        // Footer pinned to bottom.
        $footer = (string) ($content['footer'] ?? '#潘朵拉飲食');
        $this->drawText($img, $footer, self::PADDING, self::CARD_HEIGHT - 140, 32, $accentColor, $useTtf, $fontPath);

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** @return array{0:int,1:int,2:int} */
    private function parseHex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return [0xD8, 0x78, 0x50];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function fontPath(): ?string
    {
        $configured = config('ritual.share_card_font_path');
        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }
        // Common font paths (Linode prod = Ubuntu noto, Mac dev = system fonts).
        $candidates = [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/Helvetica.ttc',
            '/System/Library/Fonts/Geneva.ttf',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  resource|\GdImage  $img
     */
    private function drawText($img, string $text, int $x, int $y, int $size, int $color, bool $useTtf, ?string $fontPath): void
    {
        if ($useTtf && $fontPath !== null) {
            // imagettftext baseline starts at y, so add size to push down.
            @imagettftext($img, $size * 0.75, 0, $x, $y + $size, $color, $fontPath, $text);

            return;
        }
        // Fallback: GD built-in font 5 (pixel font, max size).
        // Approximates the size by repeating draws (functional, ugly).
        imagestring($img, 5, $x, $y, $text, $color);
    }

    private function placeholderPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk'
            .'YAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
    }
}
