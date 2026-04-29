<?php

namespace Tests\Feature\Conversion;

use App\Models\LifecycleInvalidateNonce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LifecycleInvalidateWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'lifecycle-test-secret-12345';

    private const URL = '/api/internal/lifecycle/invalidate';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pandora_lifecycle_invalidate.webhook_secret', self::SECRET);
        config()->set('services.pandora_lifecycle_invalidate.webhook_window_seconds', 300);
    }

    public function test_valid_request_forgets_lifecycle_cache(): void
    {
        $uuid = 'aaaaaaaa-1111-1111-1111-111111111111';
        $cacheKey = 'lifecycle:'.$uuid;
        Cache::put($cacheKey, ['stage' => 'loyalist'], 3600);
        $this->assertNotNull(Cache::get($cacheKey));

        $body = $this->buildBody($uuid, 'loyalist', 'applicant');

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_replay_returns_200_duplicate_via_nonce(): void
    {
        $body = $this->buildBody('aaaaaaaa-2222-2222-2222-222222222222', null, 'loyalist');
        $ts = now()->toIso8601String();
        $nonce = bin2hex(random_bytes(16));
        $sig = 'sha256='.hash_hmac('sha256', "{$ts}.{$nonce}.{$body}", self::SECRET);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PANDORA_TIMESTAMP' => $ts,
            'HTTP_X_PANDORA_NONCE' => $nonce,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ];

        $this->call('POST', self::URL, [], [], [], $headers, $body)->assertOk();
        $second = $this->call('POST', self::URL, [], [], [], $headers, $body);
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, LifecycleInvalidateNonce::count());
    }

    public function test_wrong_signature_rejected_401(): void
    {
        $body = $this->buildBody('x', null, 'loyalist');

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_TIMESTAMP' => now()->toIso8601String(),
            'HTTP_X_PANDORA_NONCE' => 'abcd1234',
            'HTTP_X_PANDORA_SIGNATURE' => 'sha256=bogus',
        ], $body)->assertStatus(401);
    }

    public function test_missing_signature_headers_rejected_401(): void
    {
        $body = $this->buildBody('x', null, 'loyalist');

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_timestamp_too_old_rejected_401(): void
    {
        $body = $this->buildBody('x', null, 'loyalist');

        $oldTs = now()->subHour()->toIso8601String();
        $nonce = 'abcd1234';
        $sig = 'sha256='.hash_hmac('sha256', "{$oldTs}.{$nonce}.{$body}", self::SECRET);

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_TIMESTAMP' => $oldTs,
            'HTTP_X_PANDORA_NONCE' => $nonce,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ], $body)->assertStatus(401);
    }

    public function test_missing_secret_returns_500(): void
    {
        config()->set('services.pandora_lifecycle_invalidate.webhook_secret', '');
        $body = $this->buildBody('x', null, 'loyalist');

        $this->postWithSig($body)->assertStatus(500);
    }

    public function test_missing_uuid_acks_200_ignored(): void
    {
        $body = (string) json_encode([
            'from_status' => 'loyalist',
            'to_status' => 'applicant',
        ]);
        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('status', 'ignored');
    }

    private function buildBody(string $uuid, ?string $from, string $to): string
    {
        return (string) json_encode([
            'from_status' => $from,
            'pandora_user_uuid' => $uuid,
            'to_status' => $to,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postWithSig(string $body)
    {
        $ts = now()->toIso8601String();
        $nonce = bin2hex(random_bytes(16));
        $sig = 'sha256='.hash_hmac('sha256', "{$ts}.{$nonce}.{$body}", self::SECRET);

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PANDORA_TIMESTAMP' => $ts,
            'HTTP_X_PANDORA_NONCE' => $nonce,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ], $body);
    }
}
