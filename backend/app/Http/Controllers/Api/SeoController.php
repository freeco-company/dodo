<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function __construct(private readonly SeoService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->list()]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:191'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:16'],
        ]);
        return response()->json($this->service->upsert($data));
    }

    public function sitemap(): Response
    {
        return response($this->service->sitemapXml(), 200)
            ->header('Content-Type', 'application/xml');
    }
}
