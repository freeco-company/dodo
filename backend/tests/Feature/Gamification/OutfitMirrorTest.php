<?php

namespace Tests\Feature\Gamification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutfitMirrorTest extends TestCase
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

    public function test_outfit_unlocked_merges_codes_into_outfits_owned(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'omir1111-1111-1111-1111-omir11111111',
            'outfits_owned' => ['none'],
        ]);

        $body = $this->buildBody(
            'gamification.outfit_unlocked',
            $user->pandora_user_uuid,
            [
                'codes' => ['scarf', 'glasses'],
                'awarded_via' => 'level_up',
                'trigger_level' => 8,
                'occurred_at' => '2026-04-29T12:00:00+00:00',
            ],
            eventId: 'gamification.outfit_unlocked.10',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('event_type', 'gamification.outfit_unlocked')
            ->assertJsonPath('mirrored', 2);

        $user->refresh();
        $this->assertEqualsCanonicalizing(['none', 'scarf', 'glasses'], $user->outfits_owned);
    }

    public function test_outfit_unlocked_idempotent_skips_already_owned(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'omir2222-2222-2222-2222-omir22222222',
            'outfits_owned' => ['none', 'scarf'],
        ]);

        $body = $this->buildBody(
            'gamification.outfit_unlocked',
            $user->pandora_user_uuid,
            ['codes' => ['scarf', 'glasses']],
            eventId: 'evt-skip-owned',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', 1);

        $user->refresh();
        $this->assertEqualsCanonicalizing(['none', 'scarf', 'glasses'], $user->outfits_owned);
    }

    public function test_outfit_unlocked_unknown_user_drops_silently(): void
    {
        $body = $this->buildBody(
            'gamification.outfit_unlocked',
            'omir3333-3333-3333-3333-omir33333333',
            ['codes' => ['scarf']],
            eventId: 'evt-no-user',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', 0);
    }

    public function test_outfit_unlocked_empty_codes_is_noop(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'omir4444-4444-4444-4444-omir44444444',
            'outfits_owned' => ['none'],
        ]);
        $body = $this->buildBody(
            'gamification.outfit_unlocked',
            $user->pandora_user_uuid,
            ['codes' => []],
            eventId: 'evt-empty-codes',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', 0);

        $user->refresh();
        $this->assertEqualsCanonicalizing(['none'], $user->outfits_owned);
    }

    public function test_outfit_unlocked_initial_null_outfits_owned_seeds_with_none_plus_codes(): void
    {
        $user = User::factory()->create([
            'pandora_user_uuid' => 'omir5555-5555-5555-5555-omir55555555',
            'outfits_owned' => null,
        ]);

        $body = $this->buildBody(
            'gamification.outfit_unlocked',
            $user->pandora_user_uuid,
            ['codes' => ['scarf']],
            eventId: 'evt-null-owned',
        );

        $this->postWithSig($body)
            ->assertOk()
            ->assertJsonPath('mirrored', 1);

        $user->refresh();
        $this->assertEqualsCanonicalizing(['none', 'scarf'], $user->outfits_owned);
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
