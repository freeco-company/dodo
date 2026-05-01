<?php

/**
 * CardService 加盟卡 gating — 非加盟者抽不到 fp_recipe / franchise / franchise_only=true 卡。
 *
 * 注意：CardFpProductFilterTest 已測 query 層 fp_recipe 過濾（合規 flag），這個檔案測的是
 * 上面再多一層的 is_franchisee gate（即使 flag 開啟、非加盟者也抽不到）。
 */

use App\Models\User;
use App\Services\CardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isFranchiseOnlyCard detects all three FP markers', function () {
    $svc = app(CardService::class);

    expect($svc->isFranchiseOnlyCard(['tier_required' => 'fp_franchise']))->toBeTrue();
    expect($svc->isFranchiseOnlyCard(['category' => 'fp_recipe']))->toBeTrue();
    expect($svc->isFranchiseOnlyCard(['category' => 'franchise']))->toBeTrue();
    expect($svc->isFranchiseOnlyCard(['franchise_only' => true]))->toBeTrue();

    expect($svc->isFranchiseOnlyCard(['category' => 'protein', 'tier_required' => 'free']))->toBeFalse();
    expect($svc->isFranchiseOnlyCard([]))->toBeFalse();
});

it('respects is_franchisee flag with franchise filter', function () {
    $franchisee = User::factory()->create(['is_franchisee' => true]);
    $regular = User::factory()->create(['is_franchisee' => false]);

    expect((bool) $franchisee->is_franchisee)->toBeTrue();
    expect((bool) $regular->is_franchisee)->toBeFalse();
});
