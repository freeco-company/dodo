<?php

use App\Jobs\PublishConversionEventJob;
use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Enable publisher so jobs are actually dispatched
    config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
    config()->set('services.pandora_conversion.shared_secret', 'test-secret');
    Bus::fake();
});

it('fires app.opened on bootstrap when authenticated', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '11111111-1111-1111-1111-111111111111',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk();

    Bus::assertDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'app.opened'
            && $job->body['pandora_user_uuid'] === '11111111-1111-1111-1111-111111111111';
    });
});

it('does not fire app.opened on anonymous bootstrap', function () {
    $this->getJson('/api/bootstrap')->assertOk();
    Bus::assertNotDispatched(PublishConversionEventJob::class);
});

it('fires franchise.cta_view on POST /api/franchise/cta-view', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '22222222-2222-2222-2222-222222222222',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/franchise/cta-view', ['source' => 'paywall'])
        ->assertOk();

    Bus::assertDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'franchise.cta_view'
            && $job->body['payload']['source'] === 'paywall';
    });
});

it('fires franchise.cta_click on POST /api/franchise/cta-click', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '33333333-3333-3333-3333-333333333333',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/franchise/cta-click', ['source' => 'home_banner'])
        ->assertOk();

    Bus::assertDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'franchise.cta_click';
    });
});

it('rejects franchise CTA endpoints without auth', function () {
    $this->postJson('/api/franchise/cta-view')->assertStatus(401);
    $this->postJson('/api/franchise/cta-click')->assertStatus(401);
});

it('fires engagement.deep when streak reaches 7 consecutive days', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '44444444-4444-4444-4444-444444444444',
    ]);

    // Pre-seed 6 consecutive days ending yesterday
    for ($i = 1; $i <= 6; $i++) {
        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->subDays($i)->toDateString(),
        ]);
    }

    // Today's checkin creates the 7th consecutive day
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 500])
        ->assertOk();

    Bus::assertDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'engagement.deep'
            && $job->body['payload']['streak_days'] === 7;
    });
});

it('does not double-fire engagement.deep within idempotency window', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '55555555-5555-5555-5555-555555555555',
    ]);
    Cache::put(
        "conversion:engagement_deep_fired:{$user->pandora_user_uuid}",
        true,
        now()->addDays(30),
    );

    for ($i = 1; $i <= 6; $i++) {
        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->subDays($i)->toDateString(),
        ]);
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 500])
        ->assertOk();

    // app.opened may fire from other paths but engagement.deep must not.
    Bus::assertNotDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'engagement.deep';
    });
});

it('does not fire engagement.deep with a streak gap', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '66666666-6666-6666-6666-666666666666',
    ]);

    // Days 1, 2, 3 missing, but 4, 5, 6, today exist → 4 day streak only
    for ($i = 1; $i <= 3; $i++) {
        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->subDays($i + 3)->toDateString(),
        ]);
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 500])
        ->assertOk();

    Bus::assertNotDispatched(PublishConversionEventJob::class, function ($job) {
        return $job->body['event_type'] === 'engagement.deep';
    });
});
