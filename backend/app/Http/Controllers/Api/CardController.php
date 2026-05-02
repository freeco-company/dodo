<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardEventOffer;
use App\Services\CardService;
use App\Services\SeasonalContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function __construct(
        private readonly CardService $service,
        private readonly SeasonalContentService $seasonal,
    ) {}

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

    /**
     * SPEC-seasonal-outfit-cards Phase 1 — completion summary by category
     * + active / upcoming seasonal windows for the Cards tab progress UI.
     */
    public function completion(Request $request): JsonResponse
    {
        return response()->json([
            'completion' => $this->service->completionSummary($request->user()),
            'seasonal_active' => $this->seasonal->activeAt(),
            'seasonal_upcoming' => array_slice($this->seasonal->upcomingAt(), 0, 3),
        ]);
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
            return response()->json(['has_offer' => false]);
        }

        $payload = $this->service->eventOfferShow($user, (int) $offer->id);

        return response()->json([
            'has_offer' => true,
            'card' => $payload['card'],
            'offer' => [
                'id' => $payload['id'],
                'card_id' => $payload['card_id'],
                'status' => $payload['status'],
                'event_group' => $payload['event_group'],
                'expires_at' => $payload['expires_at'],
                'offered_at' => $payload['offered_at'],
            ],
            'data' => $payload,
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
