<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function __construct(private readonly CardService $service) {}

    public function draw(Request $request): JsonResponse
    {
        return response()->json($this->service->draw($request->user()));
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'play_id' => ['required', 'integer'],
            'choice_idx' => ['required', 'integer', 'min:0', 'max:10'],
        ]);
        return response()->json($this->service->answer(
            $request->user(),
            (int) $data['play_id'],
            (int) $data['choice_idx'],
        ));
    }

    public function stamina(Request $request): JsonResponse
    {
        return response()->json($this->service->getStamina($request->user()));
    }

    public function collection(Request $request): JsonResponse
    {
        return response()->json($this->service->collection($request->user()));
    }
}
