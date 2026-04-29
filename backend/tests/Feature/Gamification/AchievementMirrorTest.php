<?php

namespace Tests\Feature\Gamification;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementMirrorTest extends TestCase
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

    public function test_achievement_awarded_creates_local_mirror_row(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir1111-1111-1111-1111-amir11111111',
        ]);

        $body = $this->buildBody('gamification.achievement_awarded', $user->pandora_user_uuid, [
            'code' => 'dodo.first_meal',
            'name' => '第一餐',
            'tier' => 'bronze',
            'source_app' => 'dodo',
            'occurred_at' => '2026-04-29T12:00:00+00:00',
        ], eventId: 'gamification.achievement_awarded.10');

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', true)
            ->assertJsonPath('event_type', 'gamification.achievement_awarded');

        $row = Achievement::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('dodo.first_meal', $row->achievement_key);
        $this->assertSame('第一餐', $row->achievement_name);
        $this->assertNotNull($row->unlocked_at);
    }

    public function test_replay_does_not_duplicate_local_row(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir2222-2222-2222-2222-amir22222222',
        ]);

        $payload = [
            'code' => 'dodo.streak_7',
            'name' => '一週有你',
            'tier' => 'silver',
            'source_app' => 'dodo',
            'occurred_at' => '2026-04-29T12:00:00+00:00',
        ];
        $b1 = $this->buildBody('gamification.achievement_awarded', $user->pandora_user_uuid, $payload, eventId: 'evt-1');
        $b2 = $this->buildBody('gamification.achievement_awarded', $user->pandora_user_uuid, $payload, eventId: 'evt-2');

        $this->postWithSig($b1)->assertOk()->assertJsonPath('mirrored', true);
        $this->postWithSig($b2)
            ->assertOk()
            ->assertJsonPath('mirrored', false);

        $this->assertSame(1, Achievement::where('user_id', $user->id)->count());
    }

    public function test_replay_via_same_event_id_short_circuits_at_middleware(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir3333-3333-3333-3333-amir33333333',
        ]);
        $body = $this->buildBody('gamification.achievement_awarded', $user->pandora_user_uuid, [
            'code' => 'dodo.streak_30',
            'name' => '一個月的陪伴',
            'tier' => 'gold',
            'source_app' => 'dodo',
        ], eventId: 'shared-event-id');

        $first = $this->postWithSig($body);
        $first->assertOk()->assertJsonPath('mirrored', true);

        // Same event_id → middleware nonce dedup → 200 'duplicate' before
        // it reaches the controller switch; mirror count must stay 1.
        $second = $this->postWithSig($body);
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Achievement::where('user_id', $user->id)->count());
    }

    public function test_unknown_pandora_user_drops_silently_with_mirrored_false(): void
    {
        $body = $this->buildBody(
            'gamification.achievement_awarded',
            'amir4444-4444-4444-4444-amir44444444',
            ['code' => 'dodo.first_meal', 'name' => '第一餐', 'tier' => 'bronze'],
            eventId: 'evt-no-user',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', false);
    }

    public function test_missing_code_does_not_create_row(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir5555-5555-5555-5555-amir55555555',
        ]);
        $body = $this->buildBody(
            'gamification.achievement_awarded',
            $user->pandora_user_uuid,
            ['name' => 'no code'],
            eventId: 'evt-missing-code',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', false);

        $this->assertSame(0, Achievement::where('user_id', $user->id)->count());
    }

    public function test_falls_back_to_code_when_name_missing(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir6666-6666-6666-6666-amir66666666',
        ]);
        $body = $this->buildBody(
            'gamification.achievement_awarded',
            $user->pandora_user_uuid,
            ['code' => 'dodo.foodie_10'],
            eventId: 'evt-name-fallback',
        );

        $this->postWithSig($body)->assertOk()->assertJsonPath('mirrored', true);

        $row = Achievement::where('user_id', $user->id)->first();
        $this->assertSame('dodo.foodie_10', $row->achievement_name);
    }

    public function test_uses_payload_occurred_at_when_present(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'amir7777-7777-7777-7777-amir77777777',
        ]);
        $body = $this->buildBody(
            'gamification.achievement_awarded',
            $user->pandora_user_uuid,
            [
                'code' => 'dodo.first_meal',
                'name' => '第一餐',
                'occurred_at' => '2026-01-01T00:00:00+00:00',
            ],
            eventId: 'evt-occurred-at',
        );

        $this->postWithSig($body)->assertOk();

        $row = Achievement::where('user_id', $user->id)->first();
        $this->assertSame('2026-01-01', $row->unlocked_at->toDateString());
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
