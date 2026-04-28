<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResource;
use App\Services\FoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodController extends Controller
{
    public function __construct(private readonly FoodService $service) {}

    /**
     * GET /api/foods/search?q=...
     *
     * Mirrors legacy ai-game/src/app.ts /api/foods/search.  Empty query → empty
     * data array (200, not 422) so the iOS suggest box can call this on every
     * keystroke without paying for validation errors.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
        ]);

        $query = (string) ($data['q'] ?? '');
        $foods = $this->service->search($query, 20);

        return FoodResource::collection($foods)->response();
    }
}
