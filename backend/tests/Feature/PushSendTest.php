<?php

use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('send returns no_tokens when user has none registered', function () {
    $user = User::factory()->create();
    $result = app(PushService::class)->send($user, 'hi', 'test');
    expect($result)->toMatchArray(['sent' => 0, 'skipped' => 0, 'reason' => 'no_tokens']);
});

it('send skips with fcm_not_configured when keys missing', function () {
    config(['services.fcm.service_account_json' => '', 'services.fcm.project_id' => '']);

    $user = User::factory()->create();
    app(PushService::class)->register($user, 'ios', 'apns-token-1');
    $result = app(PushService::class)->send($user, 'hi', 'body');
    expect($result['sent'])->toBe(0);
    expect($result['skipped'])->toBe(1);
    expect($result['reason'])->toBe('fcm_not_configured');
});

it('register persists token with dual-write uuid', function () {
    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-000000000abc']);
    $id = app(PushService::class)->register($user, 'android', 'fcm-token-xyz');
    expect($id)->toBeGreaterThan(0);
    $row = DB::table('push_tokens')->where('id', $id)->first();
    expect($row->pandora_user_uuid)->toBe('00000000-0000-0000-0000-000000000abc');
});
