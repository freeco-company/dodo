<?php

/**
 * 集團合規硬規則 regression guard（docs/group-fp-product-compliance.md）。
 *
 * 阻擋 PR 引入新的違規詞到：
 *   - database/seed/*.json（NPC 對白、題卡、商店意圖）
 *   - database/seeders/Kb*Seeder.php（KB OCR seeders）
 *   - app_config 載入後的 question_decks（運行時驗證）
 *
 * 新加 KB seeder / JSON entry 必須先過合規 sanitizer。違規詞清單見
 * Pandora\Shared\Compliance\LegalContentSanitizer::REPLACEMENTS。
 */

use Pandora\Shared\Compliance\LegalContentSanitizer;

it('all seed JSON files are clean of forbidden terms', function () {
    $sanitizer = new LegalContentSanitizer;
    $base = database_path('seed');
    $files = glob($base . '/*.json') ?: [];
    expect($files)->not->toBeEmpty();

    $offenders = [];
    foreach ($files as $f) {
        $hits = $sanitizer->riskReport((string) file_get_contents($f));
        if ($hits) {
            $offenders[basename($f)] = $hits;
        }
    }
    expect($offenders)->toBe([], '違規詞請先 sanitize 再提交。Hits: ' . json_encode($offenders, JSON_UNESCAPED_UNICODE));
});

it('seed JSONs and KB seeders have no adjacent dupes of sanitized values', function () {
    // Pandora\Shared\Compliance\LegalContentSanitizer::dedupeSanitizedTerms() guards
    // 「體態管理體態管理」並列重複現象。本 test 防止 reggression。
    $vals = array_unique(array_filter(array_values(\Pandora\Shared\Compliance\LegalContentSanitizer::REPLACEMENTS), fn ($v) => $v !== ''));
    usort($vals, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $files = array_merge(
        glob(database_path('seed/*.json')) ?: [],
        glob(database_path('seeders/KbOcrBatch*Seeder.php')) ?: [],
    );
    $offenders = [];
    foreach ($files as $f) {
        $c = (string) file_get_contents($f);
        foreach ($vals as $v) {
            $q = preg_quote($v, '/');
            if (preg_match('/' . $q . $q . '/u', $c, $m)) {
                $offenders[basename($f)][] = $m[0];
            }
        }
    }
    expect($offenders)->toBe([], '出現 sanitizer-output 詞並列重複，請跑 sanitizeText 修復。Hits: ' . json_encode($offenders, JSON_UNESCAPED_UNICODE));
});

it('all KB OCR seeder files are clean of forbidden terms', function () {
    $sanitizer = new LegalContentSanitizer;
    $files = glob(database_path('seeders/KbOcrBatch*Seeder.php')) ?: [];
    expect($files)->not->toBeEmpty();

    $offenders = [];
    foreach ($files as $f) {
        $hits = $sanitizer->riskReport((string) file_get_contents($f));
        if ($hits) {
            $offenders[basename($f)] = $hits;
        }
    }
    expect($offenders)->toBe([], '違規詞請先 sanitize 再提交。Hits: ' . json_encode($offenders, JSON_UNESCAPED_UNICODE));
});
