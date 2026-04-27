<?php

use App\Http\Controllers\Api\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SEO sitemap — public, served at root per convention.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
