<?php

use App\Rules\HibpUncompromisedPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.hibp_enabled' => true]);
    Cache::flush();
});

/**
 * SHA-1('password') = 5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8
 * Prefix: 5BAA6 / Suffix: 1E4C9B93F3F0682250B6CF8331B7EE68FD8
 */
it('fails validation when password is pwned (count above threshold)', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/5BAA6' => Http::response(
            "1E4C9B93F3F0682250B6CF8331B7EE68FD8:9876543\nDEADBEEF:1\n",
            200
        ),
    ]);

    $v = Validator::make(
        ['password' => 'password'],
        ['password' => [new HibpUncompromisedPassword(threshold: 1)]]
    );

    expect($v->fails())->toBeTrue();
});

it('passes when suffix is not present in HIBP response', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("AAAA:1\nBBBB:2\n", 200),
    ]);

    $v = Validator::make(
        ['password' => 'totally-unique-passphrase-2026'],
        ['password' => [new HibpUncompromisedPassword(threshold: 1)]]
    );

    expect($v->fails())->toBeFalse();
});

it('fails-open (passes) when HIBP API errors', function () {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 503),
    ]);

    $v = Validator::make(
        ['password' => 'any-password-here'],
        ['password' => [new HibpUncompromisedPassword(threshold: 1)]]
    );

    expect($v->fails())->toBeFalse();
});

it('caches HIBP responses to avoid hammering the API', function () {
    Http::fake([
        'api.pwnedpasswords.com/range/5BAA6' => Http::response("1E4C9B93F3F0682250B6CF8331B7EE68FD8:5\n", 200),
    ]);

    $rule = new HibpUncompromisedPassword(threshold: 1);

    // First call hits the wire
    $v1 = Validator::make(['password' => 'password'], ['password' => [$rule]]);
    $v1->fails();

    // Second call should be cache-served
    $v2 = Validator::make(['password' => 'password'], ['password' => [$rule]]);
    $v2->fails();

    Http::assertSentCount(1);
});

it('is a no-op when HIBP_CHECK_ENABLED is false', function () {
    config(['app.hibp_enabled' => false]);
    Http::fake(); // any call would fail this fake (no matching pattern)

    $v = Validator::make(
        ['password' => 'password'], // would otherwise be flagged
        ['password' => [new HibpUncompromisedPassword(threshold: 1)]]
    );

    expect($v->fails())->toBeFalse();
    Http::assertNothingSent();
});
