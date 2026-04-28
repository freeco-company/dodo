<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardEventOffer;
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

    public function eventOffer(Request $request, int $offerId): JsonResponse
    {
        return response()->json([
            'data' => $this->service->eventOfferShow($request->user(), $offerId),
        ]);
    }

    /**
     * GET /api/cards/event-offer/next — current active offer for the user, or
     * 204 if none. Replaces the legacy /cards/event-offer/:userId endpoint
     * (the offer_id was always derived from "first active row" anyway).
     */
    public function eventOfferNext(Request $request): JsonResponse
    {
        $user = $request->user();
        $offer = CardEventOffer::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('offered_at')
            ->first();

        if (! $offer) {
            return response()->json(null, 204);
        }

        return response()->json([
            'data' => $this->service->eventOfferShow($user, (int) $offer->id),
        ]);
    }

    public function eventDraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
        ]);

        return response()->json($this->service->eventDraw(
            $request->user(),
            (int) $data['offer_id'],
        ));
    }

    public function eventSkip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => ['required', 'integer'],
        ]);

        return response()->json($this->service->eventSkip(
            $request->user(),
            (int) $data['offer_id'],
        ));
    }

    public function sceneDraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_id' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($this->service->sceneDraw(
            $request->user(),
            (string) $data['card_id'],
        ));
    }
}
