<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.admin_token' => 'seo-admin']);
});

it('admin can upsert seo meta', function () {
    $this->putJson('/api/admin/seo', [
        'path' => 'landing',
        'title' => 'Doudou — 減脂夥伴',
        'description' => '養成 + AI 陪跑',
    ], ['X-Admin-Token' => 'seo-admin'])->assertOk();

    expect(DB::table('seo_metas')->where('path', 'landing')->exists())->toBeTrue();
});

it('admin can list seo metas', function () {
    DB::table('seo_metas')->insert([
        'path' => 'landing', 'title' => 't', 'description' => 'd', 'updated_at' => now(),
    ]);
    $this->getJson('/api/admin/seo', ['X-Admin-Token' => 'seo-admin'])
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('rejects seo admin without token', function () {
    $this->getJson('/api/admin/seo')->assertStatus(403);
});

it('serves a sitemap.xml', function () {
    DB::table('seo_metas')->insert([
        'path' => 'landing', 'title' => 't', 'description' => 'd', 'updated_at' => now(),
    ]);
    $resp = $this->get('/sitemap.xml');
    $resp->assertOk();
    expect($resp->headers->get('Content-Type'))->toContain('application/xml');
    expect($resp->getContent())->toContain('<urlset');
});
