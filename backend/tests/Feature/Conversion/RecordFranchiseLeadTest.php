<?php

use App\Events\ConversionEventPublished;
use App\Events\UserOptedOutFranchiseCta;
use App\Listeners\RecordFranchiseLead;
use App\Listeners\SilenceFranchiseLeads;
use App\Models\DodoUser;
use App\Models\FranchiseLead;
use App\Models\User;
use App\Services\Conversion\ConversionEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'http://py.test');
    config()->set('services.pandora_conversion.shared_secret', 'shh');
    Cache::flush();
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 202),
    ]);
});

it('creates a lead when franchise.cta_click is published', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-lead-001']);
    DodoUser::firstOrCreate(
        ['pandora_user_uuid' => 'uuid-lead-001'],
        ['display_name' => 'Lead 1'],
    );

    app(ConversionEventPublisher::class)->publish(
        'uuid-lead-001',
        'franchise.cta_click',
        ['source' => 'me_tab'],
    );

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-001')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->trigger_event)->toBe('franchise.cta_click')
        ->and($lead->status)->toBe(FranchiseLead::STATUS_NEW)
        ->and($lead->source_app)->toBe('doudou')
        ->and($lead->user_id)->toBe($user->id);
});

it('does not duplicate lead on repeated cta_click for same user', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-dup']);

    app(ConversionEventPublisher::class)->publish('uuid-lead-dup', 'franchise.cta_click');
    app(ConversionEventPublisher::class)->publish('uuid-lead-dup', 'franchise.cta_click');
    app(ConversionEventPublisher::class)->publish('uuid-lead-dup', 'franchise.cta_click');

    expect(FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-dup')->count())->toBe(1);
});

it('skips non-tracked events (app.opened / engagement.deep / cta_view)', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-skip']);

    app(ConversionEventPublisher::class)->publish('uuid-lead-skip', 'app.opened');
    app(ConversionEventPublisher::class)->publish('uuid-lead-skip', 'engagement.deep');
    app(ConversionEventPublisher::class)->publish('uuid-lead-skip', 'franchise.cta_view');

    expect(FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-skip')->count())->toBe(0);
});

it('records mothership.first_order as a tracked trigger', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-mom']);

    app(ConversionEventPublisher::class)->publish(
        'uuid-lead-mom',
        'mothership.first_order',
        ['order_id' => 'O-1'],
    );

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-mom')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->trigger_event)->toBe('mothership.first_order');
});

it('marks new lead as silenced if user has already opted out', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-silenced']);
    DodoUser::query()->updateOrCreate(
        ['pandora_user_uuid' => 'uuid-lead-silenced'],
        [
            'display_name' => 'Silenced',
            'franchise_cta_silenced' => true,
            'franchise_cta_silenced_at' => now(),
        ],
    );

    app(ConversionEventPublisher::class)->publish('uuid-lead-silenced', 'franchise.cta_click');

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-silenced')->first();
    expect($lead->status)->toBe(FranchiseLead::STATUS_SILENCED);
});

it('opt-out event marks existing new leads as silenced', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-optout']);
    FranchiseLead::create([
        'pandora_user_uuid' => 'uuid-lead-optout',
        'source_app' => 'doudou',
        'trigger_event' => 'franchise.cta_click',
        'status' => FranchiseLead::STATUS_NEW,
    ]);

    app(SilenceFranchiseLeads::class)->handle(
        new UserOptedOutFranchiseCta('uuid-lead-optout', true)
    );

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-optout')->first();
    expect($lead->status)->toBe(FranchiseLead::STATUS_SILENCED);
});

it('opt-out does NOT overwrite contacted / converted leads', function () {
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-keep']);
    FranchiseLead::create([
        'pandora_user_uuid' => 'uuid-lead-keep',
        'source_app' => 'doudou',
        'trigger_event' => 'franchise.cta_click',
        'status' => FranchiseLead::STATUS_CONTACTED,
        'contacted_at' => now()->subDay(),
    ]);

    app(SilenceFranchiseLeads::class)->handle(
        new UserOptedOutFranchiseCta('uuid-lead-keep', true)
    );

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-keep')->first();
    expect($lead->status)->toBe(FranchiseLead::STATUS_CONTACTED);
});

it('listener handles missing dodo_user mirror gracefully', function () {
    // 沒建 DodoUser row — 模擬 race condition / pre-Wave 1 user
    User::factory()->create(['pandora_user_uuid' => 'uuid-lead-nomirror']);

    app(RecordFranchiseLead::class)->handle(
        new ConversionEventPublished('uuid-lead-nomirror', 'franchise.cta_click', [])
    );

    $lead = FranchiseLead::query()->where('pandora_user_uuid', 'uuid-lead-nomirror')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->status)->toBe(FranchiseLead::STATUS_NEW);
});

it('skips lead recording when uuid is empty', function () {
    app(RecordFranchiseLead::class)->handle(
        new ConversionEventPublished('', 'franchise.cta_click', [])
    );

    expect(FranchiseLead::query()->count())->toBe(0);
});
