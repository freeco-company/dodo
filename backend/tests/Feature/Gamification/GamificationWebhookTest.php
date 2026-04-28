<?php

namespace Tests\Feature\Gamification;

use App\Models\GamificationWebhookNonce;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gamification-test-secret-12345';

    private const URL = '/api/internal/gamification/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pandora_gamification.webhook_secret', self::SECRET);
        config()->set('services.pandora_gamification.webhook_window_seconds', 300);
    }

    public function test_level_up_payload_mirrors_users_level_and_xp(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
            'level' => 1,
            'xp' => 0,
        ]);

        $body = $this->buildBody('gamification.level_up', $user->pandora_user_uuid, [
            'new_level' => 5,
            'total_xp' => 300,
            'level_name_zh' => '成長期',
            'level_name_en' => 'Growing',
            'trigger_source_app' => 'jerosse',
            'trigger_event_kind' => 'jerosse.first_order',
            'trigger_ledger_id' => 42,
        ], eventId: 'gamification.level_up.42');

        $resp = $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', true);

        $user->refresh();
        $this->assertSame(5, (int) $user->level);
        $this->assertSame(300, (int) $user->xp);
    }

    public function test_lower_level_does_not_overwrite_higher_local_level(): void
    {
        // Local already at LV.10; a stale webhook for LV.5 must not regress.
        User::factory()->create([
            'pandora_user_uuid' => 'aaaaaaaa-2222-2222-2222-222222222222',
            'level' => 10,
            'xp' => 1000,
        ]);

        $body = $this->buildBody('gamification.level_up', 'aaaaaaaa-2222-2222-2222-222222222222', [
            'new_level' => 5,
            'total_xp' => 300,
        ], eventId: 'gamification.level_up.99');

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', false);

        $u = User::where('pandora_user_uuid', 'aaaaaaaa-2222-2222-2222-222222222222')->first();
        $this->assertSame(10, (int) $u->level);
        $this->assertSame(1000, (int) $u->xp);
    }

    public function test_replay_returns_200_duplicate_via_event_id(): void
    {
        User::factory()->create([
            'pandora_user_uuid' => 'aaaaaaaa-3333-3333-3333-333333333333',
            'level' => 1,
            'xp' => 0,
        ]);

        $body = $this->buildBody('gamification.level_up', 'aaaaaaaa-3333-3333-3333-333333333333', [
            'new_level' => 3,
            'total_xp' => 120,
        ], eventId: 'gamification.level_up.replay-1');

        $this->postWithSig($body)->assertOk();
        $second = $this->postWithSig($body);
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, GamificationWebhookNonce::count());
    }

    public function test_wrong_signature_rejected_401(): void
    {
        $body = $this->buildBody('gamification.level_up', 'x', ['new_level' => 1], eventId: 'evt-bad');

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PANDORA_TIMESTAMP' => now()->toIso8601String(),
            'HTTP_X_PANDORA_NONCE' => 'abcd1234',
            'HTTP_X_PANDORA_SIGNATURE' => 'sha256=bogus',
        ], $body)->assertStatus(401);
    }

    public function test_missing_signature_headers_rejected_401(): void
    {
        $body = $this->buildBody('gamification.level_up', 'x', ['new_level' => 1], eventId: 'evt-x');

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_timestamp_too_old_rejected_401(): void
    {
        $body = $this->buildBody('gamification.level_up', 'x', ['new_level' => 1], eventId: 'evt-old');

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
        config()->set('services.pandora_gamification.webhook_secret', '');
        $body = $this->buildBody('gamification.level_up', 'x', ['new_level' => 1], eventId: 'evt-secret');

        $this->postWithSig($body)->assertStatus(500);
    }

    public function test_missing_event_id_in_body_returns_422(): void
    {
        $body = json_encode([
            // event_id missing
            'event_type' => 'gamification.level_up',
            'pandora_user_uuid' => 'x',
            'payload' => ['new_level' => 1],
        ]);
        $this->postWithSig((string) $body)->assertStatus(422);
    }

    public function test_unknown_event_type_acks_200(): void
    {
        $body = $this->buildBody('gamification.something_new', 'x', [], eventId: 'evt-unknown');

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('status', 'ignored');
    }

    public function test_no_local_user_drops_silently_with_mirrored_false(): void
    {
        $body = $this->buildBody(
            'gamification.level_up',
            'aaaaaaaa-4444-4444-4444-444444444444',
            ['new_level' => 5],
            eventId: 'gamification.level_up.no-user',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', false);
    }

    private function buildBody(string $eventType, string $uuid, array $payload, string $eventId = 'evt-default'): string
    {
        return (string) json_encode([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'pandora_user_uuid' => $uuid,
            'payload' => $payload,
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
