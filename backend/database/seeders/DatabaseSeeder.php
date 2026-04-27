<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@dodo.local'],
            [
                'name' => 'Dodo Admin',
                'password' => Hash::make('dodo-admin-2026'),
                'membership_tier' => 'fp_lifetime',
                'tier_verified_at' => now(),
            ]
        );

        if (app()->environment('local')) {
            User::factory(5)->create();
        }
    }
}
