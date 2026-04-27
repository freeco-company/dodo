<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('accepts client errors anonymously', function () {
    $this->postJson('/api/client-errors', [
        'message' => 'TypeError: foo is undefined',
        'stack' => 'at index.js:42',
        'context' => ['view' => 'home'],
    ])->assertOk();

    expect(DB::table('client_errors')->count())->toBe(1);
});

it('rejects client errors without message', function () {
    $this->postJson('/api/client-errors', [])->assertStatus(422);
});
