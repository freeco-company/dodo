<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('logs a rating prompt event', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/rating-prompt/event', ['kind' => 'shown'])
        ->assertOk();
    expect(DB::table('rating_prompt_events')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('rejects an invalid kind', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/rating-prompt/event', ['kind' => 'foo'])
        ->assertStatus(422);
});
