<?php

namespace App\Http\Resources;

use App\Models\RitualEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RitualEvent */
class RitualEventResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ritual_key' => $this->ritual_key,
            'payload' => $this->payload,
            'triggered_at' => $this->triggered_at->toIso8601String(),
            'seen_at' => $this->seen_at?->toIso8601String(),
            'shared_at' => $this->shared_at?->toIso8601String(),
        ];
    }
}
