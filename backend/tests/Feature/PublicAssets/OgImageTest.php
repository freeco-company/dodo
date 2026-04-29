<?php

it('og.png exists in public/ at 1200x630 (Open Graph spec)', function () {
    $path = base_path('public/og.png');
    expect(file_exists($path))->toBeTrue();
    [$w, $h, $type] = getimagesize($path);
    expect($w)->toBe(1200)
        ->and($h)->toBe(630)
        ->and($type)->toBe(IMAGETYPE_PNG);
});

it('og.png is a reasonable size (not 1KB blank, not 10MB)', function () {
    $path = base_path('public/og.png');
    $bytes = filesize($path);
    expect($bytes)->toBeGreaterThan(5_000)
        ->and($bytes)->toBeLessThan(2_000_000);
});
