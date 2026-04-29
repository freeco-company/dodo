<?php

use App\Services\Conversion\LifecycleAdminClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
    config()->set('services.pandora_conversion.shared_secret', 'admin-secret-xyz');
});

it('posts to py-service admin override endpoint with X-Internal-Secret', function () {
    Http::fake([
        '*/internal/admin/users/*/lifecycle/transition' => Http::response([
            'id' => 7,
            'from_status' => 'loyalist',
            'to_status' => 'applicant',
        ], 201),
    ]);

    $client = app(LifecycleAdminClient::class);
    $result = $client->override(
        'aaaaaaaa-1111-1111-1111-111111111111',
        'applicant',
        'Operator confirmed by phone',
        'admin@freeco.cc',
    );

    expect($result['to_status'])->toBe('applicant');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/internal/admin/users/aaaaaaaa-1111-1111-1111-111111111111/lifecycle/transition')
            && $request->header('X-Internal-Secret')[0] === 'admin-secret-xyz'
            && $request['to_status'] === 'applicant'
            && $request['reason'] === 'Operator confirmed by phone'
            && $request['actor'] === 'admin@freeco.cc';
    });
});

it('throws RuntimeException on non-2xx so caller can audit + notify', function () {
    Http::fake([
        '*/internal/admin/users/*/lifecycle/transition' => Http::response(
            ['detail' => 'invalid lifecycle status: bogus'],
            422,
        ),
    ]);

    $client = app(LifecycleAdminClient::class);

    $client->override('uuid-bad', 'bogus', 'r', 'admin@x');
})->throws(\RuntimeException::class, 'lifecycle override failed');

it('throws when pandora_conversion is not configured', function () {
    config()->set('services.pandora_conversion.base_url', '');

    app(LifecycleAdminClient::class)->override('u', 'visitor', 'r', 'admin@x');
})->throws(\RuntimeException::class, 'pandora_conversion not configured');
