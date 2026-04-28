<?php

namespace Tests\Feature\Identity;

use App\Models\DodoUser;
use App\Models\IdentityWebhookNonce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'dodo-test-secret-12345';

    private const URL = '/api/internal/identity/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pandora_core.webhook_secret', self::SECRET);
        config()->set('services.pandora_core.webhook_window_seconds', 300);
    }

    public function test_valid_payload_creates_minimal_mirror_user(): void
    {
        $body = $this->buildBody('user.upserted', [
            'uuid' => '019dd1c9-0a76-7304-af1c-aaaaaaaaaa01',
            'display_name' => '小明',
            'avatar_url' => 'https://cdn/x.png',
            'subscription_tier' => 'premium',
            // 即使 platform 推來 PII 也會被忽略
            'email_canonical' => 'leak@should.not.land.here',
            'phone_canonical' => '0911000000',
        ]);

        $this->postWithSig($body)->assertOk();

        $u = DodoUser::find('019dd1c9-0a76-7304-af1c-aaaaaaaaaa01');
        $this->assertNotNull($u);
        $this->assertSame('小明', $u->display_name);
        $this->assertSame('premium', $u->subscription_tier);
        $this->assertNotNull($u->last_synced_at);

        // 確認沒有 email / phone column 存在（防止有人偷偷加回去）
        $this->assertFalse($u->getConnection()->getSchemaBuilder()->hasColumn('dodo_users', 'email'));
        $this->assertFalse($u->getConnection()->getSchemaBuilder()->hasColumn('dodo_users', 'phone'));
        $this->assertFalse($u->getConnection()->getSchemaBuilder()->hasColumn('dodo_users', 'password'));
    }

    public function test_replay_returns_200_duplicate(): void
    {
        $body = $this->buildBody('user.upserted', [
            'uuid' => '019dd1c9-0a76-7304-af1c-aaaaaaaaaa02',
            'display_name' => 'Replay',
        ]);

        $this->postWithSig($body, 'evt-replay')->assertOk();
        $second = $this->postWithSig($body, 'evt-replay');
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, IdentityWebhookNonce::count());
    }

    public function test_wrong_signature_rejected_401(): void
    {
        $body = $this->buildBody('user.upserted', ['uuid' => 'x']);

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_EVENT_ID' => 'evt-bad',
            'HTTP_X_PANDORA_TIMESTAMP' => (string) time(),
            'HTTP_X_PANDORA_SIGNATURE' => 'bogus',
        ], $body)->assertStatus(401);
    }

    public function test_timestamp_too_old_rejected_401(): void
    {
        $body = $this->buildBody('user.upserted', ['uuid' => 'x']);
        $oldTs = (string) (time() - 3600);
        $eid = 'evt-old';
        $sig = hash_hmac('sha256', "{$oldTs}.{$eid}.{$body}", self::SECRET);

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_EVENT_ID' => $eid,
            'HTTP_X_PANDORA_TIMESTAMP' => $oldTs,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ], $body)->assertStatus(401);
    }

    public function test_missing_secret_returns_500(): void
    {
        config()->set('services.pandora_core.webhook_secret', '');
        $body = $this->buildBody('user.upserted', ['uuid' => 'x']);

        $this->postWithSig($body)->assertStatus(500);
    }

    public function test_unknown_event_type_returns_422(): void
    {
        $body = $this->buildBody('weird.thing', ['uuid' => 'x']);

        $this->postWithSig($body)->assertStatus(422);
    }

    public function test_missing_uuid_in_data_returns_422(): void
    {
        $body = $this->buildBody('user.upserted', ['display_name' => 'no uuid']);

        $this->postWithSig($body)->assertStatus(422);
    }

    public function test_idempotent_update(): void
    {
        $body1 = $this->buildBody('user.upserted', [
            'uuid' => '019dd1c9-0a76-7304-af1c-aaaaaaaaaa03',
            'display_name' => 'first',
        ]);
        $this->postWithSig($body1, 'evt-1')->assertOk();

        $body2 = $this->buildBody('user.upserted', [
            'uuid' => '019dd1c9-0a76-7304-af1c-aaaaaaaaaa03',
            'display_name' => 'updated',
            'subscription_tier' => 'premium',
        ]);
        $this->postWithSig($body2, 'evt-2')->assertOk();

        $u = DodoUser::find('019dd1c9-0a76-7304-af1c-aaaaaaaaaa03');
        $this->assertSame('updated', $u->display_name);
        $this->assertSame('premium', $u->subscription_tier);
        $this->assertSame(1, DodoUser::count());
    }

    private function buildBody(string $type, array $data): string
    {
        return (string) json_encode([
            'event_id' => 'inner',
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postWithSig(string $body, string $eventId = 'evt-default')
    {
        $ts = (string) time();
        $sig = hash_hmac('sha256', "{$ts}.{$eventId}.{$body}", self::SECRET);

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PANDORA_EVENT_ID' => $eventId,
            'HTTP_X_PANDORA_TIMESTAMP' => $ts,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ], $body);
    }
}
