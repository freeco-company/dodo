<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns ok status with db check', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.db', 'ok')
        ->assertJsonStructure(['data' => ['status', 'time', 'db', 'app', 'env']]);
});
