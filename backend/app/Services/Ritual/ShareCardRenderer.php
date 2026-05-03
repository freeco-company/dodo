<?php

namespace App\Services\Ritual;

use App\Models\ShareCardRender;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC-progress-ritual-v1 PR #1 — share card PNG renderer.
 *
 * PR #1 ships a placeholder implementation that writes a minimal PNG
 * deterministically derived from the source content (so the contract +
 * cache + endpoints work end-to-end). PR #2 swaps in real
 * intervention/image composition with face-blurred photos + 朵朵語錄.
 *
 * Schema is finalized so the swap is local.
 */
class ShareCardRenderer
{
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

        // PR #1 placeholder: write a 1x1 transparent PNG so the contract works.
        // PR #2 replaces with real intervention/image composition.
        Storage::disk('local')->put($relPath, $this->placeholderPng());

        return ShareCardRender::create([
            'user_id' => $user->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'image_path' => $relPath,
            'checksum' => $checksum,
        ]);
    }

    private function placeholderPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk'
            .'YAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
    }
}
