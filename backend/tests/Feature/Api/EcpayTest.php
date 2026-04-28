<?php

use App\Models\EcpayCallback;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ecpay\EcpayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // ECPay's published test merchant credentials (same as the stage AIO docs).
    config([
        'services.ecpay.merchant_id' => '2000132',
        'services.ecpay.hash_key' => 'pwFHCqoQZGmho4w6',
        'services.ecpay.hash_iv' => 'EkRm7iFT261dpevs',
    ]);
});

function ecpaySign(array $params): array
{
    /** @var EcpayClient $client */
    $client = app(EcpayClient::class);
    $params['CheckMacValue'] = $client->sign($params);

    return $params;
}

it('signature round-trip verifies', function () {
    /** @var EcpayClient $client */
    $client = app(EcpayClient::class);
    $params = [
        'MerchantID' => '2000132',
        'MerchantTradeNo' => 'TEST20260428001',
        'TradeNo' => '2604281234567890',
        'RtnCode' => '1',
        'RtnMsg' => 'Succeeded',
        'TotalAmount' => '290',
    ];
    $signed = ecpaySign($params);
    expect($client->verify($signed))->toBeTrue();
});

it('notify happy path activates an ecpay subscription', function () {
    $user = User::factory()->create();
    /** @var EcpayClient $client */
    $client = app(EcpayClient::class);
    // Pre-register the order so applyNotification can find the subscription.
    $client->registerOrder($user, 'TEST20260428100', 'app_monthly');

    $params = ecpaySign([
        'MerchantID' => '2000132',
        'MerchantTradeNo' => 'TEST20260428100',
        'TradeNo' => '2604280000001',
        'RtnCode' => '1',
        'RtnMsg' => 'Succeeded',
        'TotalAmount' => '290',
    ]);

    $resp = $this->postJson('/api/ecpay/notify', $params);
    $resp->assertOk();
    $resp->assertSee('1|OK');

    $sub = Subscription::where('provider_subscription_id', 'TEST20260428100')->first();
    expect($sub->state)->toBe('active');
    expect($user->fresh()->subscription_type)->toBe('app_monthly');
});

it('notify with bad CheckMacValue returns 0|invalid_signature', function () {
    $params = [
        'MerchantID' => '2000132',
        'MerchantTradeNo' => 'TEST20260428200',
        'RtnCode' => '1',
        'CheckMacValue' => 'TOTALLY_WRONG_MAC',
    ];
    $resp = $this->postJson('/api/ecpay/notify', $params);
    $resp->assertStatus(400);
    $resp->assertSee('invalid_signature');

    // Forensic row stored, not processed:
    $row = EcpayCallback::where('merchant_trade_no', 'TEST20260428200')->first();
    expect($row->signature_valid)->toBeFalse();
    expect($row->processed_at)->toBeNull();
});

it('notify is idempotent on duplicate delivery', function () {
    $user = User::factory()->create();
    /** @var EcpayClient $client */
    $client = app(EcpayClient::class);
    $client->registerOrder($user, 'TEST20260428300', 'app_monthly');

    $params = ecpaySign([
        'MerchantID' => '2000132',
        'MerchantTradeNo' => 'TEST20260428300',
        'TradeNo' => '2604280000003',
        'RtnCode' => '1',
        'RtnMsg' => 'Succeeded',
        'TotalAmount' => '290',
    ]);

    $this->postJson('/api/ecpay/notify', $params)->assertOk();
    $this->postJson('/api/ecpay/notify', $params)->assertOk();

    expect(EcpayCallback::where('merchant_trade_no', 'TEST20260428300')->count())->toBe(1);
});
