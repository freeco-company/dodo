<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('redirects /admin to login when unauthenticated', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('shows admin login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('lets dodo.local admin user log into Filament panel', function () {
    $admin = User::create([
        'name' => 'Test Admin',
        'email' => 'admin@dodo.local',
        'password' => Hash::make('secret-pass'),
    ]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});

it('blocks non-allowed user from Filament panel', function () {
    $regular = User::factory()->create([
        'email' => 'regular@example.com',
        'membership_tier' => 'public',
    ]);

    $this->actingAs($regular)
        ->get('/admin')
        ->assertForbidden();
});
