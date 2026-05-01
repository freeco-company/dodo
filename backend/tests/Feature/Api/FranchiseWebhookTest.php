<?php

namespace Tests\Feature\Api;

use App\Models\DodoUser;
use App\Models\FranchiseeWebhookNonce;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 母艦 → 朵朵 franchisee 同步 webhook 整合測試。
 * 與 IdentityWebhookTest 同樣的 HMAC 框架，但 secret + nonce 表獨立。
 */
class FranchiseWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-mothership-franchise-secret';

    private const URL = '/api/internal/franchisee/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.mothership.franchise_webhook_secret', self::SECRET);
        config()->set('services.mothership.webhook_window_seconds', 300);
    }

    public function test_marks_user_as_franchisee_on_activated_uuid(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'user-uuid-1',
            'is_franchisee' => false,
        ]);

        $body = $this->buildBody('franchisee.activated', [
            'uuid' => 'user-uuid-1',
            'verified_at' => '2026-05-01T12:34:56Z',
            'source' => 'mothership_admin',
        ]);

        $this->postWithSig($body)->assertOk()->assertJsonPath('status', 'ok');

        $user->refresh();
        $this->assertTrue((bool) $user->is_franchisee);
        $this->assertNotNull($user->franchise_verified_at);
    }

    public function test_mirrors_to_dodo_user_when_present(): void
    {
        $uuid = 'mirror-uuid-1';
        User::factory()->create(['pandora_user_uuid' => $uuid]);
        // UserObserver mirrors to DodoUser automatically on User::create.
        $dodo = DodoUser::query()->where('pandora_user_uuid', $uuid)->firstOrFail();

        $body = $this->buildBody('franchisee.activated', ['uuid' => $uuid]);
        $this->postWithSig($body)->assertOk();

        $dodo->refresh();
        $this->assertTrue((bool) $dodo->is_franchisee);
        $this->assertNotNull($dodo->franchise_verified_at);
    }

    public function test_clears_flag_on_deactivated(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'deact-uuid',
            'is_franchisee' => true,
            'franchise_verified_at' => now(),
        ]);

        $body = $this->buildBody('franchisee.deactivated', [
            'uuid' => 'deact-uuid',
            'source' => 'mothership_admin',
        ]);

        $this->postWithSig($body)->assertOk()->assertJsonPath('status', 'ok');

        $user->refresh();
        $this->assertFalse((bool) $user->is_franchisee);
        $this->assertNull($user->franchise_verified_at);
    }

    public function test_matches_by_email_and_mirrors_via_uuid_lookup(): void
    {
        $uuid = 'email-path-uuid';
        User::factory()->create([
            'email' => 'alice@example.com',
            'pandora_user_uuid' => $uuid,
            'is_franchisee' => false,
        ]);
        // UserObserver mirrors User → DodoUser automatically.

        $body = $this->buildBody('franchisee.activated', ['email' => 'alice@example.com']);
        $this->postWithSig($body)->assertOk();

        $this->assertTrue((bool) User::where('email', 'alice@example.com')->first()->is_franchisee);
        $this->assertTrue((bool) DodoUser::where('pandora_user_uuid', $uuid)->first()->is_franchisee);
    }

    public function test_returns_unmatched_when_no_user(): void
    {
        $body = $this->buildBody('franchisee.activated', ['uuid' => 'never-existed']);
        $this->postWithSig($body)->assertOk()->assertJsonPath('status', 'unmatched');
    }

    public function test_unknown_event_type_returns_422(): void
    {
        $body = $this->buildBody('franchisee.exploded', ['uuid' => 'x']);
        $this->postWithSig($body)->assertStatus(422);
    }

    public function test_missing_uuid_and_email_returns_422(): void
    {
        $body = $this->buildBody('franchisee.activated', ['source' => 'mothership_admin']);
        $this->postWithSig($body)->assertStatus(422);
    }

    public function test_replay_returns_duplicate(): void
    {
        User::factory()->create(['pandora_user_uuid' => 'replay-uuid']);
        $body = $this->buildBody('franchisee.activated', ['uuid' => 'replay-uuid']);

        $this->postWithSig($body, 'evt-replay')->assertOk();
        $second = $this->postWithSig($body, 'evt-replay');
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, FranchiseeWebhookNonce::count());
    }

    public function test_wrong_signature_rejected_401(): void
    {
        $body = $this->buildBody('franchisee.activated', ['uuid' => 'x']);
        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_EVENT_ID' => 'evt-bad-sig',
            'HTTP_X_PANDORA_TIMESTAMP' => (string) time(),
            'HTTP_X_PANDORA_SIGNATURE' => 'bogus',
        ], $body)->assertStatus(401);
    }

    public function test_timestamp_too_old_rejected_401(): void
    {
        $body = $this->buildBody('franchisee.activated', ['uuid' => 'x']);
        $oldTs = (string) (time() - 3600);
        $sig = hash_hmac('sha256', "{$oldTs}.evt-old.{$body}", self::SECRET);

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_EVENT_ID' => 'evt-old',
            'HTTP_X_PANDORA_TIMESTAMP' => $oldTs,
            'HTTP_X_PANDORA_SIGNATURE' => $sig,
        ], $body)->assertStatus(401);
    }

    public function test_missing_signature_headers_rejected_401(): void
    {
        $body = $this->buildBody('franchisee.activated', ['uuid' => 'x']);
        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    private function buildBody(string $type, array $data): string
    {
        return (string) json_encode([
            'type' => $type,
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
